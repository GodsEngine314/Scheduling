<?php

use App\Enums\IntegrationEntityType;
use App\Enums\IntegrationSystem;
use App\Models\Employee;
use App\Models\IntegrationIdentity;
use App\Models\Store;
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
    ];

    $this->saved = [];

    foreach ($this->files as $path) {
        $this->saved[$path] = is_file($path) ? file_get_contents($path) : null;
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

it('says so rather than reporting a successful zero when the export is missing', function () {
    foreach ($this->files as $path) {
        @unlink($path);
    }

    // A silent zero here reads as "Humanity has nobody", which is the wrong
    // thing to believe about a file somebody simply has not copied over yet.
    (new HumanitySeeder)->run();

    expect(IntegrationIdentity::query()->where('system', IntegrationSystem::Humanity)->count())->toBe(0);
});
