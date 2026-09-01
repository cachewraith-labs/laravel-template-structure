<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\LoginRequest;
use App\Services\UserService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Session authentication for the Blade front door.
 *
 * The token sibling is App\Http\Controllers\Api\V1\AuthController. Both ask
 * UserService for the account and both equalise timing, but they differ where
 * the transport differs: this one opens a *session* and must therefore defend
 * against session fixation, which a bearer token has no equivalent of.
 *
 * Generated only when the application has no auth scaffolding of its own. If
 * laravel/ui, Breeze, Jetstream or Fortify is installed, cachewraith:install
 * skips this file and routes/web_ui.php leaves the credential routes to them
 * (the routes are guarded on class_exists, the same way the API's Sanctum
 * routes are).
 *
 * OWASP A07 (Authentication Failures), in order of the mistakes this avoids:
 *  - the session id is regenerated on login, so a fixed id captured before
 *    authentication does not become an authenticated one;
 *  - the failure message is identical for "unknown email" and "wrong
 *    password", so the form cannot be used to enumerate accounts;
 *  - Hash::check is always reached, even for an account that does not exist,
 *    so response timing does not leak existence either;
 *  - the route carries ratelimit.api:login, keyed on email + IP together;
 *  - logout invalidates the session *and* rotates the CSRF token, so the next
 *    visitor on that browser inherits neither.
 *
 * OWASP A09: every attempt is logged with the address it came from, and never
 * with the submitted password.
 */
final class LoginController extends Controller
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $email = (string) $request->validated('email');
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Burn a comparable amount of hashing work so that a missing
            // account and a wrong password cannot be told apart by timing.
            Hash::make('timing-equalisation-'.Str::random(16));
            $passwordMatches = false;
        } else {
            $passwordMatches = Hash::check(
                (string) $request->validated('password'),
                $user->getAuthPassword(),
            );
        }

        if ($user === null || ! $passwordMatches) {
            Log::warning('auth.web.login.failed', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);

            // One message for both halves of the credential pair. Thrown
            // rather than returned so it reaches the form through the same
            // error bag as a validation failure.
            throw ValidationException::withMessages([
                'email' => [__('These credentials do not match our records.')],
            ]);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));

        // A07: the single most important line in this file. Everything the
        // pre-login session held is thrown away with the old id.
        $request->session()->regenerate();

        Log::info('auth.web.login.succeeded', [
            'user_id' => $user->getKey(),
            'ip' => $request->ip(),
        ]);

        // intended() honours where the guest was headed before Authenticate
        // bounced them here; the fallback is a route we own, never '/'.
        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $userId = $request->user()?->getKey();

        Auth::guard('web')->logout();

        // Invalidate discards the session data and its id; regenerateToken
        // rotates the CSRF secret. Skipping either leaves the next user of
        // this browser holding something from the last one (A07).
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info('auth.web.logout', [
            'user_id' => $userId,
            'ip' => $request->ip(),
        ]);

        return redirect()->route('login')->with('status', __('You have been signed out.'));
    }
}
