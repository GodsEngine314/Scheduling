<?php

use App\Enums\EmployeeStatus;
use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSystem;
use App\Models\Employee;
use App\Models\IntegrationIdentity;
use App\Support\BusinessDay;
use Database\Seeders\DemoSeeder;
use Database\Seeders\EmployeeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Seeding rosters from TCP
|--------------------------------------------------------------------------
|
| The counterpart to TcpEmployeePullTest, and the two enforce OPPOSITE rules on
| purpose. TcpEmployeeReader runs on every store change and creates nothing. This
| is an explicit, out-of-band seeder, and it does write to the projection — the
| same licence StoreSeeder takes so the board is usable before hiring publishes.
|
| What it must never do is fight a replay. Two invariants carry that:
|
|   hiring_updated_at stays NULL, so EmployeeProjector's stale-event guard never
|   discards a real event in favour of a seeded row.
|
|   A person already holding this TCP id is ADOPTED, never duplicated —
|   employees.tcp_employee_id is UNIQUE, so a second row for the same person is
|   not a tidiness problem, it aborts the seed.
|
*/

const SEED_STORE_ID = 379500001;

const SEED_STORE_NUMBER = '03795-00001';

beforeEach(function () {
    config()->set('tcp.auth_mode', 'static');
    config()->set('tcp.static_token', 'tok');

    Queue::fake();
    Http::preventStrayRequests();

    $this->seed(DemoSeeder::class);
    BusinessDay::flushTimezoneCache();
});

/**
 * One TCP employee record, in the shape the live API actually returns —
 * camelCase keys, "" for unset dates, phone as a nested object.
 */
function tcpEmployee(int $employeeId, array $overrides = []): array
{
    return array_merge([
        'employeeId' => $employeeId,
        'employeeRecordId' => $employeeId + 3_000_000,
        'employeeName' => 'Alexis Roffe',
        'firstName' => 'Alexis',
        'lastName' => 'Roffe',
        'gender' => 'unspecified',
        'birthDate' => '1998-04-13',
        'email' => '',
        'phone' => ['cellphone' => '3802608928', 'homePhone' => '', 'officePhone' => ''],
        'location' => SEED_STORE_NUMBER,
        'terminationDate' => '',
        'hireDate' => '2025-01-06',
        'seniorityDate' => '',
        'defaultPayRate' => 13,
        'createdOnDateTime' => '2026-07-30T15:03:00',
    ], $overrides);
}

function fakeTcpRoster(array $employees): void
{
    Http::fake(['*' => Http::response(['data' => $employees], 200)]);
}

/** The local id the seeder derives for a TCP employee, per LOCAL_ID_BASE. */
function seededId(int $tcpEmployeeId): int
{
    return 900_000_000 + $tcpEmployeeId;
}

it('creates the store roster the board reads', function () {
    fakeTcpRoster([tcpEmployee(6415816)]);

    (new EmployeeSeeder)->run();

    $employee = Employee::query()->find(seededId(6415816));

    expect($employee)->not->toBeNull()
        ->and($employee->first_name)->toBe('Alexis')
        ->and($employee->last_name)->toBe('Roffe')
        ->and($employee->primary_store_id)->toBe(SEED_STORE_ID)
        ->and($employee->tcp_employee_id)->toBe('6415816')
        ->and($employee->current_status)->toBe(EmployeeStatus::Hired)
        ->and($employee->birth_date->toDateString())->toBe('1998-04-13')
        // The cell phone, not the office extension: this column exists to tell
        // somebody their shift moved.
        ->and($employee->primary_phone)->toBe('3802608928');
});

it('leaves hiring_updated_at null so a real event overwrites the seeded row', function () {
    fakeTcpRoster([tcpEmployee(6415816)]);

    (new EmployeeSeeder)->run();

    // EmployeeProjector's stale guard only skips when the EXISTING row has a
    // hiring_updated_at. Null here is what makes a seeded row yield to hiring.
    expect(Employee::query()->find(seededId(6415816))->hiring_updated_at)->toBeNull();
});

it('records the store assignment and the pay rate', function () {
    fakeTcpRoster([tcpEmployee(6415816)]);

    (new EmployeeSeeder)->run();

    $employee = Employee::query()->find(seededId(6415816));

    expect($employee->storeAssignments)->toHaveCount(1)
        ->and($employee->storeAssignments->first()->store_id)->toBe(SEED_STORE_ID)
        ->and($employee->payHistories)->toHaveCount(1)
        ->and((float) $employee->payHistories->first()->base_pay)->toBe(13.0)
        // TCP sends one number, so splitting it would invent the division.
        ->and((float) $employee->payHistories->first()->performance_pay)->toBe(0.0);
});

it('writes the TCP identity as synced, so the next pull reports them linked', function () {
    fakeTcpRoster([tcpEmployee(6415816)]);

    (new EmployeeSeeder)->run();

    $identity = IntegrationIdentity::query()
        ->forEntity(IntegrationEntityType::Employee, seededId(6415816), IntegrationSystem::Tcp)
        ->first();

    expect($identity)->not->toBeNull()
        ->and($identity->external_id)->toBe('6415816')
        ->and($identity->isSynced())->toBeTrue();
});

it('is idempotent: a second run changes no counts', function () {
    fakeTcpRoster([tcpEmployee(6415816), tcpEmployee(6457536, ['firstName' => 'Devan', 'lastName' => 'Moore'])]);

    (new EmployeeSeeder)->run();

    $counts = fn (): array => [
        Employee::query()->count(),
        DB::table('employee_store_assignments')->count(),
        DB::table('employee_pay_histories')->count(),
        IntegrationIdentity::query()->where('entity_type', IntegrationEntityType::Employee)->count(),
    ];

    $before = $counts();

    (new EmployeeSeeder)->run();

    expect($counts())->toBe($before);
});

it('adopts an employee who already holds the TCP id under a different key', function () {
    // The case that aborted the first real run. tcp_employee_id is UNIQUE, so
    // creating the derived row alongside this one is not a duplicate — it is a
    // constraint violation that kills the whole seed.
    $existing = Employee::query()->create([
        'first_name' => 'CARISSA',
        'last_name' => 'DYBILAS',
        'employment_type' => 'W2',
        'primary_store_id' => SEED_STORE_ID,
        'tcp_employee_id' => '6415816',
    ]);

    fakeTcpRoster([tcpEmployee(6415816)]);

    (new EmployeeSeeder)->run();

    expect(Employee::query()->where('tcp_employee_id', '6415816')->count())->toBe(1)
        ->and(Employee::query()->find(seededId(6415816)))->toBeNull()
        // Adopted in place: same row, now carrying what TCP knows.
        ->and($existing->fresh()->first_name)->toBe('Alexis');
});

it('never edits a row hiring already owns', function () {
    $hiringRow = Employee::query()->create([
        'first_name' => 'CARISSA',
        'last_name' => 'DYBILAS',
        'employment_type' => 'W2',
        'primary_store_id' => SEED_STORE_ID,
        'tcp_employee_id' => '6415816',
        // The marker: this row is the projection of a real hiring event.
        'hiring_updated_at' => now(),
    ]);

    fakeTcpRoster([tcpEmployee(6415816)]);

    (new EmployeeSeeder)->run();

    // Untouched — but still mapped, which is the half that is ours to write.
    expect($hiringRow->fresh()->first_name)->toBe('CARISSA')
        ->and(IntegrationIdentity::query()
            ->forEntity(IntegrationEntityType::Employee, (int) $hiringRow->id, IntegrationSystem::Tcp)
            ->first()?->external_id)->toBe('6415816');
});

it('reads a termination off TCP rather than dropping the row', function () {
    fakeTcpRoster([tcpEmployee(6415816, ['terminationDate' => '2026-03-01'])]);

    (new EmployeeSeeder)->run();

    $employee = Employee::query()->find(seededId(6415816));

    expect($employee->current_status)->toBe(EmployeeStatus::Terminated)
        ->and($employee->current_status_effective_date->toDateString())->toBe('2026-03-01');
});

it('keeps one row for somebody covering two stores', function () {
    fakeTcpRoster([
        tcpEmployee(6415816),
        tcpEmployee(6415816, ['location' => '03795-00002']),
    ]);

    (new EmployeeSeeder)->run();

    $employee = Employee::query()->find(seededId(6415816));

    expect(Employee::query()->where('tcp_employee_id', '6415816')->count())->toBe(1)
        ->and($employee->storeAssignments->pluck('store_id')->sort()->values()->all())
        ->toBe([SEED_STORE_ID, 379500002]);
});

it('skips a record whose location is not one of our stores', function () {
    fakeTcpRoster([tcpEmployee(6415816, ['location' => '99999-00001'])]);

    (new EmployeeSeeder)->run();

    // On no board rather than an arbitrary one.
    expect(Employee::query()->find(seededId(6415816)))->toBeNull();
});

it('prunes only what it seeded', function () {
    fakeTcpRoster([tcpEmployee(6415816)]);

    (new EmployeeSeeder)->run();

    $demoCount = Employee::query()->where('id', '<', 900_000_000)->count();

    expect(EmployeeSeeder::prune())->toBe(1)
        ->and(Employee::query()->find(seededId(6415816)))->toBeNull()
        // DemoSeeder's people, and anything hiring projected, are untouched.
        ->and(Employee::query()->count())->toBe($demoCount)
        ->and(IntegrationIdentity::query()
            ->forEntity(IntegrationEntityType::Employee, seededId(6415816), IntegrationSystem::Tcp)
            ->exists())->toBeFalse();
});
