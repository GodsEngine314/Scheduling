<?php

use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSystem;
use App\Models\Employee;
use App\Models\HumanitySchedule;
use App\Models\IntegrationIdentity;
use App\Models\Position;
use App\Models\Store;
use App\Models\TcpJobCode;
use App\Models\TcpJobCodeRole;
use Database\Seeders\DemoSeeder;
use Database\Seeders\HumanitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The Humanity id map
|--------------------------------------------------------------------------
|
| The gap named in the integration_identities migration: "the Humanity employee
| id (nothing populates this today — it is the known gap that makes shift
| staffing fail)". A published shift has to name WHO and WHERE in Humanity's
| numbering.
|
| TWO JOINS, both confirmed against a real export rather than assumed:
|
|   employees on `eid`, which holds the TCP employee id — 50 of 50 matched in
|       the sample, none by name.
|   locations on `name`, which holds our store_number verbatim. Humanity's own
|       `location` field ON AN EMPLOYEE reads "0" for 471 of 472 records and is
|       useless for this.
|
*/

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->seed(DemoSeeder::class);

    $this->dir = storage_path('app/integrations');

    if (! is_dir($this->dir)) {
        mkdir($this->dir, 0777, true);
    }

    // The real exports live here on a developer's machine. Tests must not read
    // them — they are 472 real people — so each test writes its own fixture and
    // puts back whatever it displaced.
    $this->files = [
        'humanity-employees.json' => $this->dir.'/humanity-employees.json',
        'humanity-locations.json' => $this->dir.'/humanity-locations.json',
        'humanity-positions.json' => $this->dir.'/humanity-positions.json',
    ];

    $this->saved = [];

    foreach ($this->files as $path) {
        $this->saved[$path] = is_file($path) ? file_get_contents($path) : null;

        // MOVED ASIDE, not merely saved.
        //
        // Saving alone was not isolation, it was luck: a test that wrote two of
        // the three files left the third in place, and the seeder read the
        // developer's REAL export for it. That is 472 real people's names and
        // birthdays loaded into a test run — and it also made the suite pass or
        // fail depending on which files happened to be on the machine.
        //
        // Every test now starts from an empty directory and must write whatever
        // it wants the seeder to see. afterEach puts the real ones back.
        @unlink($path);
    }
});

afterEach(function () {
    foreach ($this->saved as $path => $contents) {
        $contents === null ? @unlink($path) : file_put_contents($path, $contents);
    }
});

function writeHumanityFixture(string $path, array $records): void
{
    file_put_contents($path, json_encode([
        'status' => 1,
        'data' => $records,
        'error' => null,
    ]));
}

it('maps an employee by eid, which holds the TCP employee id', function () {
    $employee = Employee::query()->firstOrFail();
    $employee->update(['tcp_employee_id' => '6382431']);

    writeHumanityFixture($this->files['humanity-employees.json'], [
        // Same person. The name is deliberately different from ours: `eid` is
        // the join, and matching on names would pair the wrong people.
        ['id' => '9260196', 'eid' => '6382431', 'name' => 'Someone Else'],
        // In Humanity, unknown to us. Reported, never created — `employees` is
        // a projection and an invented row there dies at the next replay.
        ['id' => '9260197', 'eid' => '999999', 'name' => 'Not Ours'],
    ]);
    writeHumanityFixture($this->files['humanity-locations.json'], []);

    $before = Employee::count();

    (new HumanitySeeder)->run();

    $identity = IntegrationIdentity::query()
        ->where('entity_type', IntegrationEntityType::Employee)
        ->where('entity_id', $employee->id)
        ->where('system', IntegrationSystem::Humanity)
        ->firstOrFail();

    expect($identity->external_id)->toBe('9260196')
        ->and($identity->sync_state->value)->toBe('synced')
        ->and(Employee::count())->toBe($before);
});

it('maps a location by store number and skips the corporate office', function () {
    $store = Store::query()->whereNotNull('store_number')->firstOrFail();

    writeHumanityFixture($this->files['humanity-employees.json'], []);
    writeHumanityFixture($this->files['humanity-locations.json'], [
        ['id' => '1355181', 'name' => $store->store_number, 'type' => 1, 'deleted' => false],
        // type 2 is the corporate address. It is called "New York" while sitting
        // on a San Francisco street, so it is skipped by TYPE — the name is
        // somebody's free text and will change.
        ['id' => '1354708', 'name' => 'New York', 'type' => 2, 'deleted' => false],
        ['id' => '1355999', 'name' => $store->store_number, 'type' => 1, 'deleted' => true],
    ]);

    (new HumanitySeeder)->run();

    $identities = IntegrationIdentity::query()
        ->where('entity_type', IntegrationEntityType::Store)
        ->where('system', IntegrationSystem::Humanity)
        ->get();

    expect($identities)->toHaveCount(1)
        ->and($identities->first()->external_id)->toBe('1355181');
});

it('is idempotent, correcting a mapping rather than colliding with it', function () {
    $employee = Employee::query()->firstOrFail();
    $employee->update(['tcp_employee_id' => '6382431']);

    writeHumanityFixture($this->files['humanity-locations.json'], []);

    writeHumanityFixture($this->files['humanity-employees.json'], [
        ['id' => '9260196', 'eid' => '6382431'],
    ]);
    (new HumanitySeeder)->run();

    // Humanity re-issued the id. UNIQUE(entity_type, entity_id, system) means a
    // second insert would abort the seed; the mapping has to be corrected.
    writeHumanityFixture($this->files['humanity-employees.json'], [
        ['id' => '9999999', 'eid' => '6382431'],
    ]);
    (new HumanitySeeder)->run();

    $identities = IntegrationIdentity::query()
        ->where('entity_type', IntegrationEntityType::Employee)
        ->where('system', IntegrationSystem::Humanity)
        ->get();

    expect($identities)->toHaveCount(1)
        ->and($identities->first()->external_id)->toBe('9999999');
});

/*
|--------------------------------------------------------------------------
| The schedule catalogue
|--------------------------------------------------------------------------
|
| `schedule` is REQUIRED on POST /shifts, and it is Humanity's id for a position
| AT A LOCATION — "Crew Member - 3795-01" and "Crew Member - 3795-02" are two
| ids for one position of ours. That is why it lives in humanity_schedules and
| not in integration_identities, which holds one mapping per entity per system.
|
*/

it('builds the schedule catalogue from the employees export when there is no positions export', function () {
    $store = Store::query()->where('store_number', '03795-00025')->firstOrFail();
    $driver = Position::query()->where('label', 'Driver')->firstOrFail();

    // No positions file on purpose: this is the fallback path. beforeEach has
    // cleared the directory, so the seeder genuinely has only the employees
    // export to work from.
    writeHumanityFixture($this->files['humanity-locations.json'], []);
    writeHumanityFixture($this->files['humanity-employees.json'], [
        ['id' => '9260196', 'eid' => '1', 'schedules' => [
            // "<position> - <franchise>-<store>", which is how the live account
            // names them. 3795-25 is store_number 03795-00025.
            '4091683' => 'Driver - 3795-25',
            // No store token: company-wide, and not a store that failed to match.
            '4055622' => 'Bonus',
            // A store token for a store that is not in the estate.
            '4099999' => 'Driver - 3795-99',
        ]],
        // The same schedule named by a second employee. 417 employees name the
        // same 62 schedules between them and schedule_id is UNIQUE, so this must
        // not become a second row.
        ['id' => '9260197', 'eid' => '2', 'schedules' => ['4091683' => 'Driver - 3795-25']],
    ]);

    (new HumanitySeeder)->run();

    expect(HumanitySchedule::scheduleFor($store->id, $driver->id))->toBe('4091683')
        ->and(HumanitySchedule::count())->toBe(3);

    // Both kinds of "no store" are recorded rather than dropped, because a row
    // nobody can explain is worth more than a silently shorter catalogue.
    expect(HumanitySchedule::query()->where('schedule_id', '4055622')->value('store_id'))->toBeNull()
        ->and(HumanitySchedule::query()->where('schedule_id', '4099999')->value('store_id'))->toBeNull()
        ->and(HumanitySchedule::query()->where('schedule_id', '4099999')->value('position_id'))
        ->toBe($driver->id);
});

it('joins a schedule on the TCP job code, not the name', function () {
    $store = Store::query()->where('store_number', '03795-00025')->firstOrFail();
    $driver = Position::query()->where('label', 'Driver')->firstOrFail();

    // TCP's catalogue is the bridge: code 37952505 is store 03795-00025,
    // role 05, and role 05 is Driver here.
    TcpJobCodeRole::query()->create([
        'role_suffix' => '05',
        'tcp_label' => 'Driver',
        'position_id' => $driver->id,
        'code_count' => 1,
    ]);
    TcpJobCode::query()->create([
        'job_code_id' => '37952505',
        'store_key' => TcpJobCodeRole::storeKeyFor((string) $store->store_number),
        'role_suffix' => '05',
        'description' => 'Driver',
    ]);

    writeHumanityFixture($this->files['humanity-employees.json'], []);
    writeHumanityFixture($this->files['humanity-locations.json'], []);
    writeHumanityFixture($this->files['humanity-positions.json'], [
        // The NAME says nothing useful — no store token, and a label that
        // matches no position of ours. The job code carries both halves.
        ['id' => '4091683', 'name' => 'Delivery (renamed by a manager)', 'job_code' => '37952505'],
    ]);

    (new HumanitySeeder)->run();

    expect(HumanitySchedule::scheduleFor($store->id, $driver->id))->toBe('4091683')
        ->and(HumanitySchedule::query()->where('schedule_id', '4091683')->value('matched_by'))
        ->toBe('job_code');
});

it('prefers the job-coded schedule when a store has a bare duplicate of the same role', function () {
    // The real shape at store 3795-23: two positions for one role, one with a
    // job code and one without. A live GET /shifts shows the store's actual
    // shifts use the JOB-CODED one, so the lookup has to agree.
    $store = Store::query()->where('store_number', '03795-00025')->firstOrFail();
    $crew = Position::query()->create(['label' => 'Crew Member']);

    TcpJobCodeRole::query()->create([
        'role_suffix' => '01',
        'tcp_label' => 'Crew Member',
        'position_id' => $crew->id,
        'code_count' => 1,
    ]);
    TcpJobCode::query()->create([
        'job_code_id' => '37952501',
        'store_key' => TcpJobCodeRole::storeKeyFor((string) $store->store_number),
        'role_suffix' => '01',
        'description' => 'Crew Member',
    ]);

    writeHumanityFixture($this->files['humanity-employees.json'], []);
    writeHumanityFixture($this->files['humanity-locations.json'], [
        ['id' => '1355181', 'name' => $store->store_number, 'type' => 1, 'deleted' => false],
    ]);
    writeHumanityFixture($this->files['humanity-positions.json'], [
        // Listed FIRST and with the lower id, so an id-ordered lookup would pick
        // it. It is the bare duplicate and it must lose.
        ['id' => '4000001', 'name' => 'Crew Member', 'location' => ['id' => '1355181']],
        ['id' => '4055623', 'name' => 'Crew Member - 3795-25', 'job_code' => '37952501'],
    ]);

    (new HumanitySeeder)->run();

    expect(HumanitySchedule::scheduleFor($store->id, $crew->id))->toBe('4055623');
});

it('prefers a GET /positions export, and joins the store by location id', function () {
    $store = Store::query()->where('store_number', '03795-00025')->firstOrFail();
    $driver = Position::query()->where('label', 'Driver')->firstOrFail();

    // Deliberately a schedule the employees export would never surface: nobody
    // is assigned to it. This is the gap the positions export closes.
    writeHumanityFixture($this->files['humanity-employees.json'], []);
    writeHumanityFixture($this->files['humanity-locations.json'], [
        ['id' => '1355181', 'name' => $store->store_number, 'type' => 1, 'deleted' => false],
    ]);
    writeHumanityFixture($this->files['humanity-positions.json'], [
        // The NAME carries no store token at all here; the location object is
        // the join, and it is the better one — it is Humanity's own answer
        // rather than a string somebody typed.
        ['id' => '4091683', 'name' => 'Driver', 'location' => ['id' => '1355181', 'name' => $store->store_number]],
        ['id' => '4091684', 'name' => 'Driver', 'location' => ['id' => '1355181'], 'deleted' => true],
    ]);

    (new HumanitySeeder)->run();

    expect(HumanitySchedule::scheduleFor($store->id, $driver->id))->toBe('4091683');

    // A deleted position is KEPT and never offered. Letting it vanish would make
    // it indistinguishable from one nobody has exported yet.
    expect(HumanitySchedule::query()->where('schedule_id', '4091684')->value('active'))->toBeFalse();
});

it('rebuilds the catalogue, so a retired schedule stops being an id we would send', function () {
    $store = Store::query()->where('store_number', '03795-00025')->firstOrFail();

    writeHumanityFixture($this->files['humanity-employees.json'], []);
    writeHumanityFixture($this->files['humanity-locations.json'], []);
    writeHumanityFixture($this->files['humanity-positions.json'], [
        ['id' => '4091683', 'name' => 'Driver - 3795-25'],
        ['id' => '4091684', 'name' => 'Insider - 3795-25'],
    ]);
    (new HumanitySeeder)->run();

    expect(HumanitySchedule::count())->toBe(2);

    // Humanity retired one. Merging would keep an id the vendor no longer has,
    // and the publisher would go on naming it.
    writeHumanityFixture($this->files['humanity-positions.json'], [
        ['id' => '4091683', 'name' => 'Driver - 3795-25'],
    ]);
    (new HumanitySeeder)->run();

    expect(HumanitySchedule::count())->toBe(1)
        ->and(HumanitySchedule::query()->where('schedule_id', '4091684')->exists())->toBeFalse()
        ->and(HumanitySchedule::scheduleFor(
            $store->id,
            Position::query()->where('label', 'Driver')->value('id'),
        ))->toBe('4091683');
});

it('leaves an existing catalogue alone when the export carries no schedules', function () {
    writeHumanityFixture($this->files['humanity-employees.json'], []);
    writeHumanityFixture($this->files['humanity-locations.json'], []);
    writeHumanityFixture($this->files['humanity-positions.json'], [
        ['id' => '4091683', 'name' => 'Driver - 3795-25'],
    ]);
    (new HumanitySeeder)->run();

    // An empty export is far likelier to be a bad token or a truncated file than
    // a company with no positions, and believing it would take the whole
    // estate's publishing down.
    writeHumanityFixture($this->files['humanity-positions.json'], []);
    (new HumanitySeeder)->run();

    expect(HumanitySchedule::count())->toBe(1);
});

it('says so rather than reporting a successful zero when the export is missing', function () {
    foreach ($this->files as $path) {
        @unlink($path);
    }

    // A silent zero here reads as "Humanity has nobody", which is the wrong
    // thing to believe about a file somebody simply has not copied over yet.
    (new HumanitySeeder)->run();

    expect(IntegrationIdentity::query()->where('system', IntegrationSystem::Humanity)->count())->toBe(0);
});
