<?php

use App\Models\User;
use App\Services\EventConsume\Handlers\UserCreatedHandler;
use App\Services\EventConsume\Handlers\UserUpdatedHandler;
use Database\Seeders\TestUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Projecting a user from the auth stream
|--------------------------------------------------------------------------
|
| These exist because this was BROKEN and nothing caught it. users.password came
| from Laravel's stock migration as NOT NULL, while UserCreatedHandler writes
| only id, name and email — correctly, since auth.v1.user.* carries no password
| and scheduling authenticates nobody.
|
| So every user.created event threw, burned its five attempts and PARKED. A
| parked event is dropped from JetStream and never redelivered, so the projection
| was permanently missing every user and the only symptom was that attribution
| quietly never resolved.
|
| The lesson worth keeping: a projection handler that cannot insert is invisible
| until somebody looks for the row.
|
*/

it('projects a user the auth service created', function () {
    app(UserCreatedHandler::class)->handle([
        'id' => '01J000000000000000000000AA',
        'subject' => 'auth.v1.user.created',
        'data' => ['user' => ['id' => 4242, 'name' => 'Dana Okafor', 'email' => 'dana@pnefoods.com']],
    ]);

    $user = User::query()->find(4242);

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Dana Okafor')
        ->and($user->email)->toBe('dana@pnefoods.com')
        // NULL, not an empty hash. There is no password here to check, and a ''
        // could be mistaken for a credential by anything that tried.
        ->and($user->password)->toBeNull();
});

it('replays the same event without duplicating the row', function () {
    $event = [
        'id' => '01J000000000000000000000BB',
        'subject' => 'auth.v1.user.created',
        'data' => ['user' => ['id' => 4243, 'name' => 'Ben Ortiz', 'email' => 'ben@pnefoods.com']],
    ];

    app(UserCreatedHandler::class)->handle($event);
    app(UserCreatedHandler::class)->handle($event);

    // updateOrCreate on the upstream id is what makes a stream replay a no-op
    // rather than a unique-constraint failure on email.
    expect(User::query()->where('id', 4243)->count())->toBe(1);
});

it('applies a name change without inventing a password', function () {
    app(UserCreatedHandler::class)->handle([
        'id' => '01J000000000000000000000CC',
        'subject' => 'auth.v1.user.created',
        'data' => ['user' => ['id' => 4244, 'name' => 'Cleo Nash', 'email' => 'cleo@pnefoods.com']],
    ]);

    app(UserUpdatedHandler::class)->handle([
        'id' => '01J000000000000000000000DD',
        'subject' => 'auth.v1.user.updated',
        'data' => ['user' => ['id' => 4244], 'changed_fields' => ['name' => ['from' => 'Cleo Nash', 'to' => 'Cleo Marsh']]],
    ]);

    $user = User::query()->find(4244);

    expect($user->name)->toBe('Cleo Marsh')
        ->and($user->password)->toBeNull();
});

it('seeds the test account with the id the auth service uses', function () {
    $this->seed(TestUserSeeder::class);

    $user = User::query()->find(TestUserSeeder::USER_ID);

    // The id has to match pizzasys byte for byte. If the two drift, the token
    // names one user and this table has another under that key — every change
    // attributed to the wrong person, with nothing failing to show it.
    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('test@pnefoods.com')
        // The password lives in pizzasys. This side could not verify one if it
        // had it.
        ->and($user->password)->toBeNull();
});
