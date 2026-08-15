<?php

use App\Models\User;
use App\Services\Auth\TokenIntrospection;
use App\Services\Auth\TokenIntrospector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Sign the test in, as the auth service would have.
 *
 * Every route in this service now requires a token the auth service issued, so
 * a feature test that hits one has to say who it is.
 *
 * IT STUBS THE INTROSPECTOR RATHER THAN FAKING THE HTTP CALL, deliberately.
 * Http::fake() stubs match in registration order and several files here register
 * a '*' catch-all for a vendor; an introspection POST would match that first and
 * be answered with a shift body, which reads as an invalid token and 401s the
 * test for reasons that have nothing to do with what it was testing. Binding the
 * introspector keeps the auth seam out of the vendor fakes entirely.
 *
 * The real introspection path is covered by AuthServiceTest, which fakes the
 * HTTP call properly and asserts the request that goes out.
 *
 * Defaults to super-admin because most tests are about scheduling behaviour, not
 * about permissions. Pass roles/permissions explicitly to test a gate.
 *
 * @param  array<int,string>  $roles
 * @param  array<int,string>  $permissions
 */
function signIn(array $roles = ['super-admin'], array $permissions = [], ?int $userId = null, bool $authorized = true): void
{
    $userId ??= User::query()->value('id');

    $introspection = new TokenIntrospection(
        active: true,
        authorized: $authorized,
        userId: $userId === null ? null : (int) $userId,
        name: $userId === null ? null : (string) User::query()->whereKey($userId)->value('name'),
        email: null,
        roles: $roles,
        permissions: $permissions,
    );

    app()->instance(
        TokenIntrospector::class,
        new class($introspection) extends TokenIntrospector
        {
            // No parent::__construct(): the AuthServiceClient it would take is
            // never touched on this path, and requiring one would drag the whole
            // HTTP stack into a stub.
            public function __construct(private readonly TokenIntrospection $result) {}

            public function introspect(string $userToken, string $method, string $path, ?string $routeName = null): TokenIntrospection
            {
                return $this->result;
            }

            public function forget(string $userToken): void {}
        }
    );

    // A token still has to be PRESENT — the middleware looks for one before it
    // asks anything. The header works for the API and the console alike, which
    // is why it is used rather than seeding a session.
    test()->withHeader('Authorization', 'Bearer test-token');
}
