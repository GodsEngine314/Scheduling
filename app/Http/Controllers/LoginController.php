<?php

namespace App\Http\Controllers;

use App\Services\Auth\DevBypass;
use App\Services\Auth\TokenIntrospector;
use App\Support\ActingUser;
use App\Support\AuthContext;
use App\Support\Integrations\Auth\AuthServiceClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * The console's way in. It is a relay, not an authenticator.
 *
 * SCHEDULING NEVER SEES A STORED CREDENTIAL. The form collects an email and a
 * password, hands them straight to the auth service, keeps the token that comes
 * back and forgets the password in the same request. There is no password column
 * here, no hash, no verification — `users` is a projection of auth.v1.user.* and
 * anything written to it is erased by the next replay.
 *
 * So the session holds one thing: the token. Roles and permissions are asked for
 * per request rather than cached in it, which is why a permission revoked
 * upstream takes effect here without anyone logging out.
 */
class LoginController extends Controller
{
    public function __construct(
        private readonly AuthServiceClient $authService,
        private readonly TokenIntrospector $introspector,
        private readonly AuthContext $auth,
        private readonly DevBypass $devBypass,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        if ($this->auth->isAuthenticated()) {
            return redirect()->route('board');
        }

        return view('auth.login', ['devBypass' => $this->devBypass->enabled()]);
    }

    public function store(Request $request): RedirectResponse
    {
        // The bypass is checked BEFORE validation, because its username is not
        // an email and the rule below would reject it before it was ever tried.
        if ($this->devBypass->matches((string) $request->input('email'), (string) $request->input('password'))) {
            $request->session()->regenerate();
            $request->session()->put(AuthContext::SESSION_TOKEN_KEY, DevBypass::SENTINEL);
            $request->session()->forget(ActingUser::SESSION_KEY);

            return redirect()->intended(route('board'))
                ->with('err', 'Signed in with the LOCAL DEVELOPMENT BYPASS. No credential was checked by anybody.');
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $response = $this->authService->login($data['email'], $data['password']);
        } catch (Throwable $e) {
            // The message is deliberately vague about WHICH half failed. A form
            // that distinguishes "no such account" from "wrong password" is a
            // way to enumerate accounts.
            return back()
                ->withInput($request->only('email'))
                ->with('err', 'Could not sign in. Check the email and password, or try again shortly.');
        }

        $token = $this->tokenFrom($response);

        if ($token === null) {
            return back()
                ->withInput($request->only('email'))
                ->with('err', 'The auth service accepted the sign-in but returned no token.');
        }

        // Regenerated BEFORE the token goes in, so a session id an attacker
        // planted cannot be one that later carries a real token.
        $request->session()->regenerate();
        $request->session()->put(AuthContext::SESSION_TOKEN_KEY, $token);

        // The picker is a development affordance from before there was a login,
        // and it attributes changes to whoever it names. A real identity has to
        // clear it or the two disagree about who is acting.
        $request->session()->forget(ActingUser::SESSION_KEY);

        return redirect()->intended(route('board'))->with('ok', 'Signed in.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $token = $request->session()->get(AuthContext::SESSION_TOKEN_KEY);

        if (is_string($token)) {
            $this->introspector->forget($token);
        }

        // LOCAL ONLY. The token stays valid at the auth service until it
        // expires — revoking it means calling POST /auth/logout there AS the
        // user, which is a second auth shape this client does not have. Worth
        // adding, but not worth pretending: signing out of the console does not
        // currently revoke the token.
        $request->session()->forget(AuthContext::SESSION_TOKEN_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('ok', 'Signed out.');
    }

    /**
     * pizzasys answers {success, message, data: {token, token_type, ...}}.
     * The fallbacks cover a bare envelope rather than guessing at new spellings.
     *
     * @param  array<string,mixed>  $response
     */
    private function tokenFrom(array $response): ?string
    {
        foreach (['data.token', 'token', 'data.access_token'] as $path) {
            $value = data_get($response, $path);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
