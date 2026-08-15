<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\TokenIntrospector;
use App\Support\AuthContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every request through here carries a token the auth service issued, or it does
 * not proceed.
 *
 * WHERE THE TOKEN COMES FROM, in order:
 *
 *   Authorization: Bearer ...   an API client, holding its own token.
 *   session                     the console, which put it there at login.
 *
 * Both are the same token and get the same treatment. The console is not a
 * second authentication scheme — it is a browser that happens to keep the token
 * in a session cookie instead of a header.
 *
 * 401 VERSUS 403, and the difference is not cosmetic. An inactive token means we
 * do not know who this is: 401, go and log in. An active token that the upstream
 * rules refuse means we know exactly who this is and the answer is no: 403,
 * logging in again will not help. Collapsing them sends a browser round a login
 * loop that cannot succeed.
 */
class AuthenticateWithAuthService
{
    public function __construct(
        private readonly TokenIntrospector $introspector,
        private readonly AuthContext $auth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokenFrom($request);

        if ($token === null) {
            return $this->unauthenticated($request, 'No authentication token was supplied.');
        }

        $introspection = $this->introspector->introspect(
            $token,
            $request->method(),
            // The PATH the auth service matches its rules against. Leading slash,
            // no host and no query string — a rule written for /api/shifts must
            // not be defeated by ?include=cost.
            '/'.ltrim($request->path(), '/'),
            $request->route()?->getName(),
        );

        if (! $introspection->active) {
            return $this->unauthenticated($request, 'The authentication token is not valid.');
        }

        $request->attributes->set(AuthContext::REQUEST_ATTRIBUTE, $introspection);

        // This is what makes ActingUser resolve for real, and with it every
        // created_by_user_id / approved_by_user_id in the service.
        //
        // find(), so a null is possible: the token is valid but the users
        // PROJECTION has not caught up with the auth.v1.user.created event yet.
        // That must not refuse the request — the authority has already vouched
        // for them — and attribution correctly falls to null, because those
        // columns are foreign keys into a row that genuinely is not there yet.
        $request->setUserResolver(
            fn () => $introspection->userId === null ? null : User::query()->find($introspection->userId)
        );

        if ((bool) config('auth_service.enforce_authorization', true) && ! $introspection->authorized) {
            return $this->forbidden($request, $introspection->requiredPermissions);
        }

        return $next($request);
    }

    /** Header first: an explicit bearer beats whatever the session remembers. */
    private function tokenFrom(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null && trim($bearer) !== '') {
            return $bearer;
        }

        $session = $request->hasSession()
            ? $request->session()->get(AuthContext::SESSION_TOKEN_KEY)
            : null;

        return is_string($session) && trim($session) !== '' ? $session : null;
    }

    private function unauthenticated(Request $request, string $message): Response
    {
        if ($this->expectsJson($request)) {
            return response()->json(['message' => $message], 401);
        }

        // intended() so the console returns to the board somebody was looking at
        // rather than dumping them on the default page.
        return redirect()->guest(route('login'))->with('err', $message);
    }

    /** @param  array<int,string>  $required */
    private function forbidden(Request $request, array $required): Response
    {
        $message = 'You do not have permission to do that.';

        if ($this->expectsJson($request)) {
            return response()->json([
                'message' => $message,
                // Named so a client can say WHICH permission is missing rather
                // than only that something was.
                'required_permissions' => $required,
            ], 403);
        }

        return redirect()->route('board')->with('err', $message.($required === []
            ? ''
            : ' Missing: '.implode(', ', $required).'.'));
    }

    private function expectsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }
}
