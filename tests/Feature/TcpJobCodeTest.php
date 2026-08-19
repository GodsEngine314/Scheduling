<?php

use App\Enums\IntegrationEntityType;
use App\Models\Employee;
use App\Models\IntegrationIdentity;
use App\Models\Position;
use App\Models\Store;
use App\Models\TcpJobCode;
use App\Models\TcpJobCodeRole;
use App\Models\WorkSegment;
use App\Services\Scheduling\TcpWorkSegmentWriter;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The TCP job code on an outbound punch
|--------------------------------------------------------------------------
|
| TCP rejects POST /worksegments outright without one:
|
|   {"errors":[{"item":1,"details":[{"error":"The jobCodeId must have a value.",
|    "field":"jobCodeId"}]}]}
|
| The code encodes franchise, store and role in one number. The inbound path
| decoded it from day one; nothing outbound ever built one, so every
| hand-entered punch 400'd — it appeared on the board and never reached TCP,
| which is the worst shape a sync bug can take.
|
*/

beforeEach(function () {
    // Static-token auth, as TcpWriteBackTest does it: under the default 'oauth'
    // mode the client tries to exchange credentials first and dies before it
    // ever reaches the endpoint under test.
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'test-token');

    Queue::fake();
    Http::preventStrayRequests();
    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();
    seedJobCodeRoles();
    signIn();
});

/**
 * The estate's real role mapping, as PositionSeeder built it from TCP.
 *
 * BUILT BY HAND HERE because PositionSeeder reads GET /jobcodes live, and
 * nothing in this file may touch the network. The figures are the ones actually
 * in the table — in particular 04 and 08 BOTH being Assistant Manager, at 38
 * stores and at 1, which is the whole reason the reverse lookup has to choose.
 */
function seedJobCodeRoles(): void
{
    $roles = [
        ['01', 'Crew Member', 38],
        ['02', 'Crew Leader', 38],
        ['03', 'Manager', 38],
        ['04', 'Assistant Manager', 38],
        ['05', 'Co-Manager', 38],
        ['06', 'Training', 38],
        ['07', 'Management', 1],
        // The one-store oddity that must NOT win the tiebreak.
        ['08', 'Assistant Manager', 1],
    ];

    foreach ($roles as [$suffix, $label, $count]) {
        $position = Position::query()->firstOrCreate(['label' => $label]);

        TcpJobCodeRole::query()->updateOrCreate(
            ['role_suffix' => $suffix],
            ['tcp_label' => $label, 'position_id' => $position->id, 'code_count' => $count],
        );
    }

    seedJobCodeCatalogue();
}

/**
 * The per-store codes TCP actually has, at the shape the live list has.
 *
 * THE UNEVENNESS IS THE POINT. Every store carries 01-06; only store 42 carries
 * 07 and 08. A fixture where every store had every role would let a blind
 * synthesis pass every test in this file and still invent codes in production.
 */
function seedJobCodeCatalogue(): void
{
    $rows = [];

    foreach (array_merge(range(1, 31), range(38, 44)) as $store) {
        $storeKey = sprintf('3795%02d', $store);

        // The one store in the estate that carries 07 and 08.
        $suffixes = $store === 42
            ? ['01', '02', '03', '04', '05', '06', '07', '08']
            : ['01', '02', '03', '04', '05', '06'];

        foreach ($suffixes as $suffix) {
            $rows[] = [
                'job_code_id' => $storeKey.$suffix,
                'store_key' => $storeKey,
                'role_suffix' => $suffix,
                'description' => 'Role '.$suffix,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    TcpJobCode::query()->insert($rows);
}

/**
 * Give an employee the TCP ids a real one carries.
 *
 * DemoSeeder's people have none, and the writer now refuses a punch for
 * somebody TCP has never heard of — correctly, but it would block every test
 * here on a fault none of them is about.
 */
function mapEmployeeToTcp(Employee $employee): Employee
{
    return tap($employee, static function (Employee $e): void {
        $e->forceFill([
            'tcp_employee_id' => '6573538',
            'tcp_employee_record_id' => '10092566',
        ])->saveQuietly();
    });
}

/** A hand-entered punch, the "forgot to clock in" case. */
function punch(?int $positionId): WorkSegment
{
    // A REAL ROSTER STORE, not DemoSeeder's 4821. The demo store's number has
    // no group prefix, so it cannot form a job code at all — which is correct,
    // and would make this test pass for the wrong reason.
    return WorkSegment::query()->create([
        'store_id' => 379500010,
        'employee_id' => mapEmployeeToTcp(Employee::query()->firstOrFail())->id,
        'position_id' => $positionId,
        'business_date' => '2026-08-11',
        'time_in' => '2026-08-11 21:00:00',
        'time_out' => '2026-08-12 03:00:00',
        'break_minutes' => 0,
    ]);
}

function crewMemberId(): int
{
    return (int) Position::query()->where('label', 'Crew Member')->value('id');
}

/** TCP's real create envelope: data is a LIST, because the body is repeatable. */
function fakeTcpCreate(string $id = '17727488'): void
{
    Http::fake([
        '*worksegments*' => Http::response(['data' => [['id' => $id]], 'errors' => []]),
    ]);
}

it('reproduces the estate own worked example', function () {
    // From the tcp_job_code_roles migration: 37954202 is "Crew Leader - 3795-42".
    $crewLeader = (int) Position::query()->where('label', 'Crew Leader')->value('id');

    expect(TcpJobCodeRole::jobCodeIdFor('03795-00042', $crewLeader))->toBe('37954202');
});

it('picks the suffix the estate actually uses when a position has two', function () {
    // 04 and 08 are both Assistant Manager — 38 stores against 1. A position
    // does not name one code, so the tiebreak is code_count, which is the fact
    // that column was recorded to supply. Adopting the one-store oddity for
    // everybody would book 38 stores' hours against the wrong code.
    $assistant = (int) Position::query()->where('label', 'Assistant Manager')->value('id');

    expect(TcpJobCodeRole::roleSuffixFor($assistant))->toBe('04')
        ->and(TcpJobCodeRole::jobCodeIdFor('03795-00010', $assistant))->toBe('37951004');
});

it('round-trips every mapped position through the code and back', function () {
    // STORE 42, the only one carrying every suffix. At any other store
    // Management resolves to null on purpose, which is the subject of its own
    // test rather than a hole in this one.
    foreach (TcpJobCodeRole::query()->pluck('position_id')->unique() as $positionId) {
        $code = TcpJobCodeRole::jobCodeIdFor('03795-00042', (int) $positionId);

        expect($code)->not->toBeNull()
            ->and(TcpJobCodeRole::positionIdFor($code))->toBe((int) $positionId);
    }
});

it('refuses to guess rather than booking hours against the wrong role', function () {
    $crew = crewMemberId();

    expect(TcpJobCodeRole::jobCodeIdFor('03795-00010', null))->toBeNull()
        // A store number that is not one.
        ->and(TcpJobCodeRole::jobCodeIdFor('nonsense', $crew))->toBeNull()
        // A store past 99 has no representation in the two digits the code
        // allows, and a truncated code would name a DIFFERENT store.
        ->and(TcpJobCodeRole::jobCodeIdFor('03795-00142', $crew))->toBeNull()
        // A position TCP has never heard of.
        ->and(TcpJobCodeRole::jobCodeIdFor('03795-00010', 999999))->toBeNull();
});

it('sends the job code on a create, so TCP does not reject the punch', function () {
    fakeTcpCreate();

    $segment = punch(crewMemberId());

    app(TcpWorkSegmentWriter::class)->push($segment->fresh(['store', 'position', 'employee']));

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return str_contains($request->url(), 'worksegments')
            && ($body[0]['jobCodeId'] ?? null) !== null;
    });
});

it('records the id TCP hands back, so the next edit updates instead of duplicating', function () {
    // THE EXPENSIVE ONE. A live create answers {"data":[{"id":...}]} — a LIST,
    // because the body posted is repeatable. Reading that as data.id found
    // nothing, the row was marked synced with a null id, and the next edit took
    // the create branch again: two TCP segments for one block of worked time,
    // which is a duplicate on somebody's paycheque.
    fakeTcpCreate();

    $segment = punch(crewMemberId());

    app(TcpWorkSegmentWriter::class)->push($segment->fresh(['store', 'position', 'employee']));

    expect($segment->fresh()->tcp_segment_id)->toBe('17727488');
});

it('keeps the vendor own words when a push is rejected', function () {
    // "HTTP 400" on its own is unactionable when every wire key is a guess: it
    // says the payload was wrong without saying which part.
    Http::fake([
        '*worksegments*' => Http::response(
            ['errors' => [['message' => 'The jobCodeId must have a value.']]],
            400,
        ),
    ]);

    $segment = punch(crewMemberId());

    app(TcpWorkSegmentWriter::class)->push($segment->fresh(['store', 'position', 'employee']));

    expect($segment->fresh()->tcp_sync_error)->toContain('The jobCodeId must have a value.');
});

it('does not spend a round trip to be told what it already knew', function () {
    // A punch with no position can produce no job code, so TCP will refuse it.
    // Saying so locally puts the fixable thing in tcp_sync_error instead of a
    // vendor complaint about a field the manager has never heard of. No HTTP
    // fake here on purpose: a stray request would fail this test.
    $segment = punch(null);

    app(TcpWorkSegmentWriter::class)->push($segment->fresh(['store', 'position', 'employee']));

    expect($segment->fresh()->tcp_sync_error)->toContain('no position on it');
});

it('never sends a job code TCP does not have', function () {
    // THE GUARANTEE, checked exhaustively rather than by example. Every store
    // on the roster, every position TCP knows about: whatever comes back must
    // exist in the catalogue, or be refused outright.
    $positions = TcpJobCodeRole::query()->pluck('position_id')->unique();
    $invented = [];
    $resolved = 0;

    foreach (Store::query()->pluck('store_number') as $storeNumber) {
        foreach ($positions as $positionId) {
            $code = TcpJobCodeRole::jobCodeIdFor($storeNumber, (int) $positionId);

            if ($code === null) {
                continue;
            }

            $resolved++;

            if (! TcpJobCode::query()->where('job_code_id', $code)->exists()) {
                $invented[] = $storeNumber.' / position '.$positionId.' -> '.$code;
            }
        }
    }

    expect($invented)->toBe([])
        // And it is not passing by refusing everything.
        ->and($resolved)->toBeGreaterThan(200);
});

it('refuses a role that exists at some stores but not this one', function () {
    // Management is a real TCP role at exactly one store. Asking for it
    // anywhere else is asking for a code that does not exist, and the only safe
    // answer is no — a well-formed 37951007 would be an invention.
    $management = (int) Position::query()->where('label', 'Management')->value('id');

    expect(TcpJobCodeRole::jobCodeIdFor('03795-00042', $management))->toBe('37954207')
        ->and(TcpJobCodeRole::jobCodeIdFor('03795-00010', $management))->toBeNull()
        ->and(TcpJobCodeRole::jobCodeIdFor('03795-00001', $management))->toBeNull();
});

it('falls back to the estate-wide code where the one-store variant is absent', function () {
    // Assistant Manager is 04 at 38 stores and 08 at one. Both exist at store
    // 42, where the higher count wins; only 04 exists elsewhere.
    $assistant = (int) Position::query()->where('label', 'Assistant Manager')->value('id');

    expect(TcpJobCodeRole::jobCodeIdFor('03795-00042', $assistant))->toBe('37954204')
        ->and(TcpJobCodeRole::jobCodeIdFor('03795-00010', $assistant))->toBe('37951004');
});

it('still builds a code when the catalogue has never been read', function () {
    // An EMPTY catalogue means "nobody has run PositionSeeder here", not "the
    // estate has no job codes". Treating those the same would refuse every
    // punch in the company the first time this shipped to a fresh environment.
    TcpJobCode::query()->delete();

    expect(TcpJobCodeRole::jobCodeIdFor('03795-00010', crewMemberId()))->toBe('37951001');
});

it('refuses a punch for somebody TCP has never heard of', function () {
    // array_filter drops a null employeeId, so this would otherwise go out as a
    // body with times and no person on it — and come back as a complaint about
    // a field, when the fixable truth is that this employee is not mapped yet.
    $employee = Employee::query()->firstOrFail();

    IntegrationIdentity::query()
        ->where('entity_type', IntegrationEntityType::Employee)
        ->where('entity_id', $employee->id)
        ->delete();
    $employee->forceFill(['tcp_employee_id' => null, 'tcp_employee_record_id' => null])->saveQuietly();

    $segment = WorkSegment::query()->create([
        'store_id' => 379500010,
        'employee_id' => $employee->id,
        'position_id' => crewMemberId(),
        'business_date' => '2026-08-11',
        'time_in' => '2026-08-11 21:00:00',
        'time_out' => '2026-08-12 03:00:00',
        'break_minutes' => 0,
    ]);

    app(TcpWorkSegmentWriter::class)->push($segment->fresh(['store', 'position', 'employee']));

    expect($segment->fresh()->tcp_sync_error)->toContain('no TCP employee id');
});

it('names which of the three faults blocked the push', function () {
    // One dead end, three genuinely different causes. Telling them apart is the
    // difference between a message somebody can act on and a shrug.
    $unmapped = Position::query()->create(['label' => 'Dishwasher']);

    $noPosition = punch(null);
    app(TcpWorkSegmentWriter::class)->push($noPosition->fresh(['store', 'position', 'employee']));
    expect($noPosition->fresh()->tcp_sync_error)->toContain('no position on it');

    $notATcpRole = punch($unmapped->id);
    app(TcpWorkSegmentWriter::class)->push($notATcpRole->fresh(['store', 'position', 'employee']));
    expect($notATcpRole->fresh()->tcp_sync_error)->toContain('not a TCP role');

    $wrongStore = punch((int) Position::query()->where('label', 'Management')->value('id'));
    app(TcpWorkSegmentWriter::class)->push($wrongStore->fresh(['store', 'position', 'employee']));
    expect($wrongStore->fresh()->tcp_sync_error)->toContain('no Management job code at store');
});

it('offers only the positions this store can actually file at TCP', function () {
    // THE FIX FOR THE WHOLE TEAM. Driver, Insider and Shift Lead have no TCP
    // job code anywhere; offering them produced punches that saved cleanly,
    // showed on the board, and could never be pushed.
    $ids = TcpJobCodeRole::positionIdsPushableAt('03795-00010');

    $labels = Position::query()->whereIn('id', $ids)->pluck('label')->all();

    expect($labels)->toContain('Crew Member')
        ->and($labels)->not->toContain('Driver')
        ->and($labels)->not->toContain('Insider')
        ->and($labels)->not->toContain('Shift Lead')
        // Management exists at store 42 only, so it is not on offer here.
        ->and($labels)->not->toContain('Management');
});

it('offers Management at the one store that has it', function () {
    $labels = Position::query()
        ->whereIn('id', TcpJobCodeRole::positionIdsPushableAt('03795-00042'))
        ->pluck('label')->all();

    expect($labels)->toContain('Management');
});

it('refuses a hand-entered punch whose position cannot reach TCP', function () {
    // A dropdown is not a boundary: a stale page or a hand-rolled POST can
    // still carry a position this store cannot file. Refused while the manager
    // is still looking at the form, rather than silently failing to sync later.
    $driver = Position::query()->firstOrCreate(['label' => 'Driver']);

    $this->post('/board/segments', [
        'store_id' => 379500010,
        'employee_id' => mapEmployeeToTcp(Employee::query()->firstOrFail())->id,
        'position_id' => $driver->id,
        'date' => '2026-08-11',
        'time_in' => '17:00',
        'time_out' => '21:00',
    ])->assertSessionHasErrors('position_id');

    // Scoped to the store and date posted: DemoSeeder already puts Driver
    // punches at its own store, and those are not what this is about.
    expect(WorkSegment::query()
        ->where('store_id', 379500010)
        ->where('position_id', $driver->id)
        ->exists())->toBeFalse();
});

it('still records hours at a store that is not in TCP at all', function () {
    // "You picked a role TCP does not have here" and "this store is not in TCP"
    // are different situations. Refusing every option at an unintegrated store
    // would invent a scheduling problem to solve a timeclock one.
    $driver = Position::query()->firstOrCreate(['label' => 'Driver']);

    $this->post('/board/segments', [
        'store_id' => DemoSeeder::STORE_ID,
        'employee_id' => Employee::query()->value('id'),
        'position_id' => $driver->id,
        'date' => '2026-08-11',
        'time_in' => '17:00',
        'time_out' => '21:00',
    ])->assertSessionHas('ok');
});

it('repairs a punch stuck against a role TCP cannot file', function () {
    // Before this the correction dialog moved only the clocks, so the only way
    // out of the stuck state was to delete evidence of worked hours and retype
    // it. This is the path Alex's punch needed.
    $driver = Position::query()->firstOrCreate(['label' => 'Driver']);

    $stuck = punch($driver->id);
    app(TcpWorkSegmentWriter::class)->push($stuck->fresh(['store', 'position', 'employee']));
    expect($stuck->fresh()->tcp_sync_error)->toContain('not a TCP role');

    fakeTcpCreate('17727999');

    $this->put('/board/segments/'.$stuck->id, [
        'date' => '2026-08-11',
        'time_in' => '17:00',
        'time_out' => '21:00',
        'position_id' => crewMemberId(),
    ])->assertSessionHas('ok');

    expect($stuck->fresh()->position_id)->toBe(crewMemberId());
});

it('will not let a correction re-file a punch under a role TCP cannot take', function () {
    $shiftLead = Position::query()->firstOrCreate(['label' => 'Shift Lead']);
    $segment = punch(crewMemberId());

    $this->put('/board/segments/'.$segment->id, [
        'date' => '2026-08-11',
        'time_in' => '17:00',
        'time_out' => '21:00',
        'position_id' => $shiftLead->id,
    ])->assertSessionHasErrors('position_id');

    expect($segment->fresh()->position_id)->toBe(crewMemberId());
});

/*
|--------------------------------------------------------------------------
| What the dropdowns offer
|--------------------------------------------------------------------------
|
| The guard above is the boundary and stays the boundary — a stale page or a
| hand-rolled POST still has to be refused. These are about not walking a
| manager into it in the first place: a role on offer looks filable, and Driver,
| Insider and Shift Lead never were.
|
*/

/**
 * Every <option> label in EVERY select with this name, flattened.
 *
 * Every one of them, because the fault being tested for is a single form
 * somebody forgot: checking the first select on the page would have passed
 * happily while the planned-shift form below it still offered Driver.
 *
 * Read off the rendered HTML rather than the view data on purpose — the view
 * data was already correct when the dropdown was wrong.
 */
function optionsOf(string $html, string $selectName): array
{
    preg_match_all(
        '/<select[^>]*name="'.preg_quote($selectName, '/').'"[^>]*>(.*?)<\/select>/s',
        $html,
        $selects,
    );

    $labels = [];

    foreach ($selects[1] as $body) {
        preg_match_all('/<option[^>]*>(.*?)<\/option>/s', $body, $options);

        foreach ($options[1] as $label) {
            $label = trim(html_entity_decode(strip_tags($label)));

            // The "— none —" and "— open shift —" placeholders are not roles.
            if ($label !== '' && ! str_starts_with($label, '—')) {
                $labels[] = $label;
            }
        }
    }

    return $labels;
}

it('offers only TCP roles in every position dropdown on the week view', function () {
    // Both forms, not just the hand-entry one. A plan needs no job code of its
    // own, but the hours somebody works against a Driver shift still cannot be
    // filed, and the dropdown was where that shift came from.
    $html = $this->get('/board/week?store=379500010&view=both')->assertOk()->getContent();

    // The hand-entry form, the planned-shift form and the punch-correction
    // dialog, all three at once — see optionsOf().
    $labels = optionsOf($html, 'position_id');

    expect($labels)->toContain('Crew Member')
        ->and($labels)->not->toContain('Driver')
        ->and($labels)->not->toContain('Insider')
        ->and($labels)->not->toContain('Shift Lead')
        // Store 42's alone, so not here.
        ->and($labels)->not->toContain('Management');
});

it('offers Management on the week view only at the store that has it', function () {
    $html = $this->get('/board/week?store=379500042&view=both')->assertOk()->getContent();

    expect(optionsOf($html, 'position_id'))->toContain('Management');
});

it('offers only TCP roles in the day board dropdowns', function () {
    $html = $this->get('/board?store=379500010')->assertOk()->getContent();

    $labels = optionsOf($html, 'position_id');

    expect($labels)->toContain('Crew Member')
        ->and($labels)->not->toContain('Driver')
        ->and($labels)->not->toContain('Insider')
        ->and($labels)->not->toContain('Shift Lead');
});

it('keeps a shift on its own role in the edit form when TCP has no code for it', function () {
    // THE ONE PLACE THE FILTER MUST NOT WIN. Dropping the shift's own position
    // would preselect whatever came first, so saving a time change would
    // silently re-file the shift under a role nobody rostered — the filter
    // causing the exact class of quiet error it exists to prevent.
    $driver = Position::query()->firstOrCreate(['label' => 'Driver']);

    $shift = App\Models\Shift::query()->create([
        'store_id' => 379500010,
        'employee_id' => Employee::query()->value('id'),
        'position_id' => $driver->id,
        'business_date' => '2026-08-11',
        'start_at' => '2026-08-11 22:00:00',
        'end_at' => '2026-08-12 02:00:00',
    ]);

    $html = $this->get('/board?store=379500010&date=2026-08-11')->assertOk()->getContent();

    expect($html)->toContain('Driver — no TCP job code')
        ->and($html)->toContain('value="'.$shift->position_id.'" selected');
});

it('offers the estate\'s roles at a store TCP does not carry, and says so', function () {
    // The demo store's number has no franchise prefix, so it forms no job code
    // at all. An empty required select is a dead form; the roles TCP does use
    // are offered instead, with the reason nothing can be pushed stated on the
    // form rather than discovered on a chip afterwards.
    $html = $this->get('/board/week?store='.DemoSeeder::STORE_ID.'&view=both')->assertOk()->getContent();

    $labels = optionsOf($html, 'position_id');

    expect($labels)->toContain('Crew Member')
        ->and($labels)->not->toContain('Driver')
        ->and($html)->toContain('TCP has no job codes for store');
});
