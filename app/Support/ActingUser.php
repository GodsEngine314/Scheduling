<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who is making the change.
 *
 * THIS IS NOT AUTHENTICATION. It records intent; it restricts nothing. Anyone
 * can pick anyone, and every route in this service is still wide open. It
 * exists because several managers share one schedule and an audit trail that
 * cannot name an actor is worthless.
 *
 * Resolution order, and the order matters:
 *
 *   1. $request->user()  — a real authenticated identity, if a guard ever
 *      populates one. Always wins.
 *   2. session('acting_user_id') — the console's picker.
 *   3. null.
 *
 * When real SSO arrives, branch 1 starts returning a user and branches 2 and 3
 * become dead code that can be deleted without touching a single caller. That
 * is the whole point of putting this behind a class rather than reading the
 * session inline.
 *
 * Identity is never read from the request BODY. A caller who could name
 * themselves could name anyone, which would make the audit trail worse than
 * useless — it would be confidently wrong.
 */
class ActingUser
{
    public const SESSION_KEY = 'acting_user_id';

    /**
     * The request is resolved per call, never captured.
     *
     * Holding it — or a memoised User — means a container instance that
     * outlives one request answers the next one with the previous actor. That
     * is not a hypothetical: it is exactly what happens under a scoped binding
     * the framework has not flushed, and it would attribute one manager's edits
     * to another. One extra lookup per call is a cheap price for never being
     * confidently wrong about who did something.
     */
    private function request(): Request
    {
        return request();
    }

    public function current(): ?User
    {
        $request = $this->request();

        // A real guard, if one is ever wired up.
        $authenticated = $request->user();

        if ($authenticated instanceof User) {
            return $authenticated;
        }

        $sessionId = $request->hasSession()
            ? $request->session()->get(self::SESSION_KEY)
            : null;

        if ($sessionId === null) {
            return null;
        }

        // A picked user can vanish: `users` is a projection, and an
        // auth.v1.user.deleted event removes the row out from under the
        // session. Resolve to null rather than blowing up mid-request.
        return User::query()->find((int) $sessionId);
    }

    public function id(): ?int
    {
        $user = $this->current();

        return $user === null ? null : (int) $user->id;
    }

    /**
     * The name to stamp on an audit row.
     *
     * Never null: a log line reading "someone changed this" is not worth the
     * row it occupies, so an unattributed action says so in as many words.
     */
    public function name(): string
    {
        return $this->current()?->name ?? 'Unattributed';
    }

    public function set(?int $userId): void
    {
        $request = $this->request();

        if (! $request->hasSession()) {
            return;
        }

        if ($userId === null) {
            $request->session()->forget(self::SESSION_KEY);
        } else {
            $request->session()->put(self::SESSION_KEY, $userId);
        }
    }

    public function clear(): void
    {
        $this->set(null);
    }
}
