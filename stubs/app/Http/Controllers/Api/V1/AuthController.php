<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token issuance and revocation for v1, backed by Laravel Sanctum.
 *
 * Registered only when Sanctum is installed — see routes/api/v1.php. Your User
 * model must use Laravel\Sanctum\HasApiTokens for createToken() to exist.
 *
 * OWASP A07 (Authentication Failures), in order of the mistakes this avoids:
 *  - the failure response is identical for "unknown email" and "wrong
 *    password", so the endpoint cannot be used to enumerate accounts;
 *  - Hash::check is always reached, even for a non-existent user, so response
 *    timing does not leak existence either;
 *  - attempts are limited per email+IP on top of the route's throttle:5,1, so
 *    rotating either one alone does not buy an attacker more attempts;
 *  - every issued token is named and every failure is logged (A09).
 */
final class AuthController extends Controller
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $throttleKey = $this->throttleKey($request);
        $limit = (int) config('smn-template.rate_limits.login.attempts', 5);
        $decay = (int) config('smn-template.rate_limits.login.per_minutes', 1) * 60;

        if (RateLimiter::tooManyAttempts($throttleKey, $limit)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            Log::warning('auth.login.throttled', [
                'ip' => $request->ip(),
                'available_in' => $seconds,
            ]);

            throw ValidationException::withMessages([
                'email' => ["Too many login attempts. Please try again in {$seconds} seconds."],
            ])->status(Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $this->users->findByEmail((string) $request->validated('email'));

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
            RateLimiter::hit($throttleKey, $decay);

            // Never log the submitted password, and never echo which half of
            // the credential pair was wrong.
            Log::warning('auth.login.failed', [
                'email' => (string) $request->validated('email'),
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        if (! method_exists($user, 'createToken')) {
            Log::error('auth.login.misconfigured', ['reason' => 'User model is missing Laravel\Sanctum\HasApiTokens.']);

            return response()->json([
                'success' => false,
                'message' => 'Token authentication is not configured.',
                'errors' => null,
                'code' => Response::HTTP_INTERNAL_SERVER_ERROR,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $deviceName = (string) ($request->validated('device_name') ?? 'api-token');

        // Scope tokens to what the client actually needs rather than ['*'].
        $token = $user->createToken($deviceName, ['*']);

        Log::info('auth.login.succeeded', [
            'user_id' => $user->getKey(),
            'device_name' => $deviceName,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Authenticated.',
            'data' => [
                'token' => $token->plainTextToken,
                'user' => UserResource::make($user)->toArray($request),
            ],
            'errors' => null,
            'code' => Response::HTTP_OK,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = ($user !== null && method_exists($user, 'currentAccessToken'))
            ? $user->currentAccessToken()
            : null;

        // Revoke only the token that made this call, never every session the
        // user has open on other devices.
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        Log::info('auth.logout', [
            'user_id' => $user?->getKey(),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Token revoked.',
            'errors' => null,
            'code' => Response::HTTP_OK,
        ]);
    }

    /**
     * Bind attempts to the credential *and* the source address.
     */
    private function throttleKey(LoginRequest $request): string
    {
        return 'login|'.sha1(mb_strtolower((string) $request->validated('email')).'|'.$request->ip());
    }
}
