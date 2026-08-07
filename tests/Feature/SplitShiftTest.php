<?php

use App\Exceptions\SchedulingException;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Scheduling\ShiftService;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Splitting a planned shift
|--------------------------------------------------------------------------
|
| A split is TWO ROWS sharing a split_group_id, never one row with a hole in
| it. Everything below defends that shape and the arithmetic that follows from
| it — most importantly that the gap between parts is unpaid and is not a
| break, because conflating the two inflates the cost of every split shift.
|
*/

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();
    $this->bd = app(BusinessDay::class);
    $this->svc = app(ShiftService::class);
    $this->today = $this->bd->toLocal(DemoSeeder::STORE_ID, now())->toDateString();
});

/** An unsplit, assigned shift to work from. */
function unsplitShift(): Shift
{
    return Shift::whereNotNull('employee_id')
        ->whereNull('split_group_id')
        ->orderBy('id')
        ->firstOrFail();
}

it('mints a group on the original and numbers the parts in order', function () {
    $part1 = unsplitShift();
    expect($part1->split_group_id)->toBeNull();

    $part2 = $this->svc->split(
        $part1,
        $this->bd->combine(DemoSeeder::STORE_ID, $this->today, '22:00:00'),
        $this->bd->combine(DemoSeeder::STORE_ID, $this->today, '23:30:00'),
    );

    $part1->refresh();

    expect($part1->split_group_id)->not->toBeNull()
        ->and($part1->split_part)->toBe(1)
        ->and($part2->split_group_id)->toBe($part1->split_group_id)
        ->and($part2->split_part)->toBe(2);
});

it('adds a third part to an existing split, keeping one group', function () {
    // Ada's shift is already a two-part split in the seed.
    $existing = Shift::whereNotNull('split_group_id')->orderBy('split_part')->firstOrFail();

    $third = $this->svc->split(
        $existing,
        $this->bd->combine(DemoSeeder::STORE_ID, $this->today, '22:00:00'),
        $this->bd->combine(DemoSeeder::STORE_ID, $this->today, '23:00:00'),
    );

    $group = Shift::where('split_group_id', $existing->split_group_id)->orderBy('split_part')->get();

    expect($third->split_part)->toBe(3)
        ->and($group)->toHaveCount(3)
        ->and($group->pluck('split_part')->all())->toBe([1, 2, 3]);
});

it('refuses a part that starts before the previous one ends', function () {
    $part1 = unsplitShift();

    expect(fn () => $this->svc->split(
        $part1,
        $part1->start_at->copy()->addMinutes(30),   // still inside part 1
        $part1->end_at->copy()->addHours(2),
    ))->toThrow(SchedulingException::class);
});

it('refuses a part that ends before it starts', function () {
    $part1 = unsplitShift();

    expect(fn () => $this->svc->split(
        $part1,
        $part1->end_at->copy()->addHours(3),
        $part1->end_at->copy()->addHours(2),
    ))->toThrow(SchedulingException::class);
});

it('gives part 2 no break, so the gap is never paid', function () {
    $part1 = unsplitShift();
    $part1->forceFill(['unpaid_break_minutes' => 30])->save();
    $before = $part1->fresh()->paidHours();

    // A two-hour gap, then a two-hour block.
    $start = $part1->end_at->copy()->addHours(2);
    $part2 = $this->svc->split($part1->fresh(), $start, $start->copy()->addHours(2));

    expect($part2->unpaid_break_minutes)->toBe(0)
        ->and($part2->paidHours())->toBe(2.0)
        // Part 1 keeps its own break; the gap adds nothing.
        ->and(round($part1->fresh()->paidHours() + $part2->paidHours(), 2))
        ->toBe(round($before + 2.0, 2));
});

it('leaves part 1 alone when part 2 is created', function () {
    $part1 = unsplitShift();
    $before = [
        'start' => $part1->start_at->toIso8601String(),
        'end' => $part1->end_at->toIso8601String(),
        'break' => $part1->unpaid_break_minutes,
        'business_date' => $part1->business_date->toDateString(),
    ];

    $start = $part1->end_at->copy()->addHours(2);
    $this->svc->split($part1, $start, $start->copy()->addHour());

    $after = $part1->fresh();

    // Only the group columns change. Part 1's own times, break and day are
    // untouched, so punches already reconciled against it stay matched.
    expect($after->start_at->toIso8601String())->toBe($before['start'])
        ->and($after->end_at->toIso8601String())->toBe($before['end'])
        ->and($after->unpaid_break_minutes)->toBe($before['break'])
        ->and($after->business_date->toDateString())->toBe($before['business_date']);
});

it('checks each part against availability independently', function () {
    $ben = Employee::where('first_name', 'Ben')->firstOrFail();  // window closes 21:00

    $inside = $this->svc->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $ben->id,
        'start_at_local' => "{$this->today} 17:00:00",
        'end_at_local' => "{$this->today} 19:00:00",
    ]);

    $outside = $this->svc->split(
        $inside,
        $this->bd->combine(DemoSeeder::STORE_ID, $this->today, '21:30:00'),
        $this->bd->combine(DemoSeeder::STORE_ID, $this->today, '22:30:00'),
    );

    // The gap between them need not be covered — nobody is working then.
    expect($inside->availability_check->value)->toBe('ok')
        ->and($outside->availability_check->value)->toBe('outside_availability');
});

it('starts part 2 as a draft even when part 1 is already published', function () {
    $part1 = unsplitShift();
    $part1->forceFill([
        'publish_state' => 'published',
        'humanity_shift_id' => 'HS-500',
        'payload_fingerprint' => str_repeat('b', 64),
    ])->save();

    $start = $part1->end_at->copy()->addHours(2);
    $part2 = $this->svc->split($part1->fresh(), $start, $start->copy()->addHour());

    // humanity_shift_id is UNIQUE and belongs to part 1. Part 2 is a shift
    // Humanity has never seen, so it publishes as its own row.
    expect($part2->publish_state->value)->toBe('draft')
        ->and($part2->humanity_shift_id)->toBeNull()
        ->and($part2->payload_fingerprint)->toBeNull()
        ->and($part1->fresh()->publish_state->value)->toBe('published');
});

it('does not reuse the part number of a deleted part', function () {
    $part1 = unsplitShift();
    $start = $part1->end_at->copy()->addHours(2);

    $part2 = $this->svc->split($part1, $start, $start->copy()->addHour());
    $part2->delete();                                    // soft delete

    $part3 = $this->svc->split($part1->fresh(), $start->copy()->addHours(3), $start->copy()->addHours(4));

    // max(split_part) is read withTrashed, so the deleted 2 is not handed out
    // again — two live rows both numbered 2 would be indistinguishable.
    expect($part3->split_part)->toBe(3);
});

it('warns when part 2 strays onto a different business date, even if part 1 already crosses midnight', function () {
    $cleo = Employee::where('first_name', 'Cleo')->firstOrFail();
    $tomorrow = now()->parse($this->today)->addDay()->toDateString();

    // Part 1 itself runs past midnight: business_date is today, but it ENDS
    // tomorrow. Comparing part 2 against the end day instead of the business
    // date silently skipped the warning in exactly this case.
    $part1 = $this->svc->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $cleo->id,
        'start_at_local' => "{$this->today} 21:00:00",
        'end_at_local' => "{$tomorrow} 01:00:00",
    ]);

    $this->post("/board/shifts/{$part1->id}/split", [
        'date' => $tomorrow,
        'second_start' => '02:30',
        'second_end' => '04:00',
    ])->assertRedirect();

    $part2 = Shift::where('split_group_id', $part1->fresh()->split_group_id)
        ->where('split_part', 2)->firstOrFail();

    expect($part1->fresh()->business_date->toDateString())->toBe($this->today)
        ->and($part2->business_date->toDateString())->toBe($tomorrow)
        ->and(session('ok'))->toContain($tomorrow)
        ->and(session('ok'))->toContain('open that date');
});

it('does not warn when both parts share a business date', function () {
    $part1 = unsplitShift();
    $endLocal = app(BusinessDay::class)->toLocal(DemoSeeder::STORE_ID, $part1->end_at);

    $this->post("/board/shifts/{$part1->id}/split", [
        'date' => $endLocal->toDateString(),
        'second_start' => $endLocal->copy()->addMinutes(45)->format('H:i'),
        'second_end' => $endLocal->copy()->addMinutes(105)->format('H:i'),
    ])->assertRedirect();

    expect(session('ok'))->toContain('unpaid gap that is not a break')
        ->and(session('ok'))->not->toContain('open that date');
});

it('puts a part that runs past midnight on the day it started', function () {
    $cleo = Employee::where('first_name', 'Cleo')->firstOrFail();

    $part1 = $this->svc->create([
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => $cleo->id,
        'start_at_local' => "{$this->today} 20:00:00",
        'end_at_local' => "{$this->today} 22:00:00",
    ]);

    $tomorrow = now()->parse($this->today)->addDay()->toDateString();

    $part2 = $this->svc->split(
        $part1,
        $this->bd->combine(DemoSeeder::STORE_ID, $tomorrow, '00:30:00'),
        $this->bd->combine(DemoSeeder::STORE_ID, $tomorrow, '02:00:00'),
    );

    // Documented consequence, not a bug: business_date is the day the BLOCK
    // starts, so a part beginning after midnight belongs to the next day and
    // will not appear on part 1's board. The dialog warns before you do it.
    expect($part1->business_date->toDateString())->toBe($this->today)
        ->and($part2->business_date->toDateString())->toBe($tomorrow);
});
