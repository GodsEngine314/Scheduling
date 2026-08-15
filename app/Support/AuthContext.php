<?php

namespace App\Support;

use App\Services\Auth\TokenIntrospection;
use Illuminate\Http\Request;

/**
 * Who the auth service says is making this request.
 *
 * THIS IS THE THING ActingUser WAS WAITING FOR. ActingUser's docblock said
 * branch 1 — $request->user() — was the seam, and that "when real SSO arrives,
 * branch 1 starts returning a user and branches 2 and 3 become dead code". The
 * middleware now populates it, so that is exactly what happened: the session
 * picker is a fallback for local development and no longer the only answer.
 *
 * Resolved from request() per call, never captured, for the same reason
 * ActingUser does it: a container instance that outlives one request would
 * answer the next one with the previous caller's roles.
 *
 * The session key holds the TOKEN and nothing else. Roles and permissions are
 * never cached in the session — they are whatever the authority said on this
 * request, so a permission revoked upstream stops working here as soon as the
 * introspection cache expires, rather than surviving until logout.
 */
class AuthContext
{
    /** Where the middleware stashes the introspection for the current request. */
    public const REQUEST_ATTRIBUTE = 'auth.introspection';

    /** The console's copy of the auth-service token. Not the credentials. */
    public const SESSION_TOKEN_KEY = 'auth_service_token';

    private function request(): Request
    {
        return request();
    }

    /** The introspection for this request, or an inactive one on an open route. */
    public function current(): TokenIntrospection
    {
        $introspection = $this->request()->attributes->get(self::REQUEST_ATTRIBUTE);

        return $introspection instanceof TokenIntrospection
            ? $introspection
            : TokenIntrospection::inactive();
    }

    public function isAuthenticated(): bool
    {
        return $this->current()->active;
    }

    public function userId(): ?int
    {
        return $this->current()->userId;
    }

    /** Never null: an unattributed action should say so in as many words. */
    public function name(): string
    {
        $name = $this->current()->name;

        return $name === null || trim($name) === '' ? 'Unattributed' : $name;
    }

    /** @return array<int,string> */
    public function roles(): array
    {
        return $this->current()->roles;
    }

    public function hasRole(string $role): bool
    {
        return $this->current()->hasRole($role);
    }

    /**
     * A named permission, with the upstream super-role bypass applied.
     *
     * super-admin is config('authz.super_roles') in the auth service, where it
     * short-circuits every rule. Honouring it here too keeps one answer to
     * "what can this person do" rather than two that can disagree.
     */
    public function can(string $permission): bool
    {
        $current = $this->current();

        return $current->isSuperAdmin() || $current->hasPermission($permission);
    }

    public function isSuperAdmin(): bool
    {
        return $this->current()->isSuperAdmin();
    }

    /** The raw token this request carried, for a logout that revokes it. */
    public function token(): ?string
    {
        $request = $this->request();

        $bearer = $request->bearerToken();

        if ($bearer !== null && trim($bearer) !== '') {
            return $bearer;
        }

        return $request->hasSession()
            ? $request->session()->get(self::SESSION_TOKEN_KEY)
            : null;
    }
}
