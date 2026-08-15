<?php

namespace App\Services\Auth;

/**
 * What the auth service said about one token, for one request.
 *
 * TWO SEPARATE ANSWERS, and collapsing them loses the distinction that matters
 * to a caller:
 *
 *   $active      is this a real, unexpired token belonging to a real user?
 *                No  -> 401. We do not know who this is.
 *   $authorized  may that user do this method on this path?
 *                No  -> 403. We know exactly who this is and they may not.
 *
 * A 401 tells a client to log in again; a 403 tells it not to bother. Returning
 * one for the other sends people round a login loop that can never succeed.
 */
final readonly class TokenIntrospection
{
    /**
     * @param  array<int,string>  $roles
     * @param  array<int,string>  $permissions
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public bool $active,
        public bool $authorized,
        public ?int $userId = null,
        public ?string $name = null,
        public ?string $email = null,
        public array $roles = [],
        public array $permissions = [],
        public array $requiredPermissions = [],
        public ?string $grantedBy = null,
        public array $raw = [],
    ) {}

    /** An unusable token: no user, no permissions, nothing granted. */
    public static function inactive(): self
    {
        return new self(active: false, authorized: false);
    }

    /**
     * @param  array<string,mixed>  $body  the token-verify response
     */
    public static function fromResponse(array $body): self
    {
        if (($body['active'] ?? false) !== true) {
            return self::inactive();
        }

        $ext = (array) ($body['ext'] ?? []);
        $user = (array) ($body['user'] ?? []);

        return new self(
            active: true,
            // Absent means NOT authorized. An introspection response missing the
            // field is a contract we do not recognise, and the safe reading of
            // an unrecognised authorization answer is no.
            authorized: ($ext['authorized'] ?? false) === true,
            userId: isset($user['id']) ? (int) $user['id'] : null,
            name: isset($user['name']) ? (string) $user['name'] : null,
            email: isset($user['email']) ? (string) $user['email'] : null,
            roles: array_values(array_filter((array) ($body['roles'] ?? []), 'is_string')),
            permissions: array_values(array_filter((array) ($body['permissions'] ?? []), 'is_string')),
            requiredPermissions: array_values(array_filter((array) ($ext['required_permissions'] ?? []), 'is_string')),
            grantedBy: isset($ext['granted_by']) ? (string) $ext['granted_by'] : null,
            raw: $body,
        );
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * The upstream bypass role, from pizzasys' config('authz.super_roles').
     *
     * Named here because scheduling has its own reasons to ask — seeing every
     * store, and seeing pay rates — that are not the same question as "was this
     * request authorized".
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    /** @return array<string,mixed> */
    public function toSession(): array
    {
        return [
            'user_id' => $this->userId,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
        ];
    }
}
