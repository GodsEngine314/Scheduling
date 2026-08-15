<?php

use App\Enums\RequestDecision;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Services\Scheduling\EmployeeRequestService;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Filing, correcting and withdrawing a request
|--------------------------------------------------------------------------
|
| The board could decide requests and nothing could make one. These close that,
| and the shape they close it in is the constraint worth pinning:
|
|   status is a CACHE of the latest decision row. So the edit path may never
|   touch it, and "withdraw" is a CANCELLED decision rather than a DELETE — a
|   deleted row answers nothing anybody later asks about who requested what.
|
|   Correcting is PENDING ONLY. Editing a decided request would leave its
|   decision row pointing at something that was never decided.
|
|   employee_id is who the request is ABOUT; requested_by_user_id is who TYPED
|   it. Employees have no login here, so a manager filing on somebody's behalf
|   is the normal case, and the API takes the same request from an
|   employee-facing client.
|
*/

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();

    $this->requests = app(EmployeeRequestService::class);
    $this->employee = Employee::query()->where('primary_store_id', DemoSeeder::STORE_ID)->firstOrFail();

    signIn();
});

/** A pending time-off request for the demo store. */
function pendingRequest(array $overrides = []): EmployeeRequest
{
    return app(EmployeeRequestService::class)->create(array_merge([
        'employee_id' => test()->employee->id,
        'store_id' => DemoSeeder::STORE_ID,
        'request_type' => RequestType::TimeOff->value,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-03',
        'description' => 'wedding',
    ], $overrides));
}

// ── filing ──────────────────────────────────────────────────────────────

it('files a request from the console, pending, attributed to whoever typed it', function () {
    $this->post(route('board.requests.store'), [
        'employee_id' => $this->employee->id,
        'store_id' => DemoSeeder::STORE_ID,
        'request_type' => 'time_off',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-03',
        'description' => 'wedding',
    ])->assertRedirect();

    $row = EmployeeRequest::query()->latest('id')->firstOrFail();

    expect($row->status)->toBe(RequestStatus::Pending)
        ->and((int) $row->employee_id)->toBe((int) $this->employee->id)
        // Who it is ABOUT and who TYPED it are two columns, and the second is
        // read from the session rather than the form.
        ->and($row->requested_by_user_id)->not->toBeNull();
});

it('refuses a time_off request with no dates', function () {
    // "A request without dates is a note, not a request" — it would be
    // invisible to the conflict check, which is the only reason to store it.
    $this->post(route('board.requests.store'), [
        'employee_id' => $this->employee->id,
        'store_id' => DemoSeeder::STORE_ID,
        'request_type' => 'time_off',
    ])->assertRedirect();

    expect(EmployeeRequest::query()->where('description', null)->where('start_date', null)->exists())
        ->toBeFalse();
});

it('accepts the same request from the API, where an employee client files it', function () {
    $this->postJson(route('api.employee-requests.store'), [
        'employee_id' => $this->employee->id,
        'store_id' => DemoSeeder::STORE_ID,
        'request_type' => 'time_off',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-03',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'pending');
});

it('will not let a caller name the filer', function () {
    $this->postJson(route('api.employee-requests.store'), [
        'employee_id' => $this->employee->id,
        'store_id' => DemoSeeder::STORE_ID,
        'request_type' => 'other',
        // Ignored: a caller who could name the filer could name anyone.
        'requested_by_user_id' => 99999,
    ])->assertCreated();

    expect((int) EmployeeRequest::query()->latest('id')->value('requested_by_user_id'))
        ->not->toBe(99999);
});

// ── correcting ──────────────────────────────────────────────────────────

it('corrects a pending request without touching its status', function () {
    $row = pendingRequest();

    $this->putJson(route('api.employee-requests.update', $row), [
        'end_date' => '2026-09-05',
        'description' => 'wedding, extended',
    ])->assertOk()
        ->assertJsonPath('data.status', 'pending');

    $row->refresh();

    expect($row->end_date->toDateString())->toBe('2026-09-05')
        ->and($row->description)->toBe('wedding, extended')
        ->and($row->status)->toBe(RequestStatus::Pending)
        // An edit is not a decision, so it leaves no row in the trail.
        ->and($row->decisions()->count())->toBe(0);
});

it('checks a one-sided edit against the date already stored', function () {
    $row = pendingRequest(['start_date' => '2026-09-01', 'end_date' => '2026-09-03']);

    // Only end_date is sent, so after_or_equal has nothing to compare against —
    // the service is what holds the line here.
    $this->putJson(route('api.employee-requests.update', $row), [
        'end_date' => '2026-08-01',
    ])->assertStatus(422);

    expect($row->fresh()->end_date->toDateString())->toBe('2026-09-03');
});

it('refuses to edit a request that has been decided', function () {
    $row = pendingRequest();
    $this->requests->decide($row, RequestDecision::Approved);

    // Editing now would leave the approval pointing at something nobody
    // approved.
    $this->putJson(route('api.employee-requests.update', $row), [
        'end_date' => '2026-09-30',
    ])->assertStatus(422);

    expect($row->fresh()->end_date->toDateString())->toBe('2026-09-03');
});

it('never lets an edit move the subject or the status', function () {
    $row = pendingRequest();
    $other = Employee::query()->where('id', '!=', $this->employee->id)->firstOrFail();

    $this->putJson(route('api.employee-requests.update', $row), [
        'employee_id' => $other->id,
        'status' => 'approved',
        'description' => 'still mine',
    ])->assertOk();

    $row->refresh();

    expect((int) $row->employee_id)->toBe((int) $this->employee->id)
        ->and($row->status)->toBe(RequestStatus::Pending)
        ->and($row->description)->toBe('still mine');
});

// ── withdrawing ─────────────────────────────────────────────────────────

it('withdraws by appending a cancelled decision, never by deleting', function () {
    $row = pendingRequest();

    $this->post(route('board.requests.withdraw', $row))->assertRedirect();

    $row->refresh();

    expect($row->exists)->toBeTrue()
        ->and($row->status)->toBe(RequestStatus::Cancelled)
        ->and($row->decisions()->count())->toBe(1)
        ->and($row->decisions()->first()->decision)->toBe(RequestDecision::Cancelled);
});

it('withdraws an approved request and keeps the approval in the trail', function () {
    $row = pendingRequest();
    $this->requests->decide($row, RequestDecision::Approved);

    // Somebody cancelling leave they no longer need is the ordinary case.
    $this->postJson(route('api.employee-requests.withdraw', $row), ['notes' => 'not needed'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'cancelled');

    expect($row->fresh()->decisions()->count())->toBe(2)
        ->and($row->fresh()->decisions()->orderBy('id')->first()->decision)
        ->toBe(RequestDecision::Approved);
});

it('refuses to withdraw twice', function () {
    $row = pendingRequest();
    $this->requests->withdraw($row);

    $this->postJson(route('api.employee-requests.withdraw', $row))->assertStatus(422);

    expect($row->fresh()->decisions()->count())->toBe(1);
});

it('frees the date up once approved time off is withdrawn', function () {
    $row = pendingRequest(['start_date' => '2026-09-01', 'end_date' => '2026-09-01']);
    $this->requests->decide($row, RequestDecision::Approved);

    $covering = fn (): bool => EmployeeRequest::query()
        ->approvedTimeOffCovering((int) $this->employee->id, '2026-09-01')
        ->exists();

    expect($covering())->toBeTrue();

    $this->requests->withdraw($row);

    // The conflict check reads status, so a withdrawal has to actually release
    // the day rather than merely look cancelled.
    expect($covering())->toBeFalse();
});

// ── there is no destroy ─────────────────────────────────────────────────

it('exposes no delete route for requests', function () {
    // The trail is the point. Withdrawal is the delete.
    expect(collect(Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->contains(fn ($route): bool => in_array('DELETE', $route->methods(), true)
            && str_contains($route->uri(), 'employee-requests')))
        ->toBeFalse();
});
