<?php

namespace App\Support\Integrations\Auth;

use App\Exceptions\IntegrationException;
use App\Support\Integrations\AbstractApiClient;

/**
 * HTTP surface for the auth service.
 *
 * Deliberately on the same AbstractApiClient as TCP and Humanity, so the retry
 * budget, the correlation id, the key-names-only logging and the one failure
 * type all behave identically. An operator reading a log should not have to
 * learn a third shape.
 *
 * Two things differ from the vendors:
 *
 *   This is NOT a vendor. Every path and field here is CONFIRMED against
 *   pizzasys' own routes and TokenVerifyController — there is no GUESS in this
 *   file, and there should never be one.
 *
 *   The token is ours, not a user's. It authenticates SCHEDULING to the auth
 *   service; the user's token travels in the body as the thing being asked
 *   about. Do not conflate them: sending a user token in the header would
 *   authenticate nobody, and sending the service token in the body would ask
 *   the auth service to introspect its own credential.
 */
class AuthServiceClient extends AbstractApiClient
{
    private const TOKEN_VERIFY_PATH = '/auth/token-verify';

    private const LOGIN_PATH = '/auth/login';

    protected function integration(): string
    {
        return 'auth_service';
    }

    protected function authDescriptor(): array
    {
        $token = trim((string) (config('auth_service.service_token') ?? ''));

        // Refused rather than sent empty: "Bearer " earns a 401 that reads like
        // a rejected credential instead of what it is, an unset env var.
        if ($token === '') {
            throw IntegrationException::configuration(
                'auth_service',
                'auth_service.service_token is empty; scheduling cannot identify itself to the auth service.',
            );
        }

        return [
            'mode' => 'static',
            'transport' => 'header',
            'header' => 'Authorization',
            'prefix' => 'Bearer',
            'param' => null,
            'token' => $token,
        ];
    }

    /**
     * What does this user token mean, and may it do this?
     *
     * method / path / route_name are passed because the auth service answers
     * the authorization question too, against rules filed under our service
     * name. It cannot decide "may they publish a schedule" without knowing what
     * was asked for.
     *
     * @return array<string,mixed> the introspection body; ['active' => false] when the token is no good
     */
    public function verifyToken(string $userToken, string $method, string $path, ?string $routeName = null): array
    {
        return $this->post(self::TOKEN_VERIFY_PATH, [
            'service' => (string) config('auth_service.service_name', 'scheduling'),
            'token' => $userToken,
            'method' => strtoupper($method),
            'path' => $path,
            'route_name' => $routeName,
        ]);
    }

    /**
     * Exchange an email and password for a token, on the user's behalf.
     *
     * Scheduling never stores either. The console collects them, hands them
     * straight to the authority, keeps the token it gets back and forgets the
     * password — which is the only arrangement in which this service can be
     * said not to hold credentials.
     *
     * @return array<string,mixed>
     */
    public function login(string $email, string $password, string $clientType = 'web'): array
    {
        return $this->post(self::LOGIN_PATH, [
            'email' => $email,
            'password' => $password,
            'client_type' => $clientType,
        ]);
    }
}
