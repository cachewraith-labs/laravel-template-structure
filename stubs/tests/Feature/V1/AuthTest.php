<?php

declare(strict_types=1);

namespace Tests\Feature\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature tests for the v1 credential endpoints.
 *
 * These assert the security properties, not just the happy path — an auth test
 * that only checks "correct password returns 200" would pass against an
 * endpoint that accepts every password.
 */
final class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-Battery-1!';

    public function test_a_valid_credential_pair_issues_a_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data' => ['token', 'user'], 'errors', 'code']);

        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * OWASP A07: an unknown email and a wrong password must be indistinguishable.
     * If these two responses ever diverge, the endpoint is an account oracle.
     */
    public function test_unknown_email_and_wrong_password_are_indistinguishable(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        $wrongPassword = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        $unknownEmail = $this->postJson('/api/v1/login', [
            'email' => 'nobody@example.test',
            'password' => 'not-the-password',
        ]);

        $wrongPassword->assertStatus(422);
        $unknownEmail->assertStatus(422);
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
    }

    /**
     * OWASP A04 / A09: the response must never carry the hash, and the failure
     * path must never echo the submitted password back.
     */
    public function test_the_response_never_contains_a_password_or_hash(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $body = $response->getContent();

        $this->assertStringNotContainsString(self::PASSWORD, $body);
        $this->assertStringNotContainsString($user->getAuthPassword(), $body);
    }

    /**
     * OWASP A06 / A07: the login route must have a ceiling. The bucket is 5
     * attempts per minute by default (config cachewraith-template.rate_limits).
     */
    public function test_repeated_failures_are_throttled(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $attempts = (int) config('cachewraith-template.rate_limits.login.attempts', 5);

        for ($i = 0; $i < $attempts; $i++) {
            $this->postJson('/api/v1/login', [
                'email' => $user->email,
                'password' => 'wrong',
            ]);
        }

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_logout_revokes_only_the_calling_token(): void
    {
        $user = User::factory()->create();
        $keep = $user->createToken('other-device');
        $use = $user->createToken('this-device');

        $this->withHeader('Authorization', 'Bearer '.$use->plainTextToken)
            ->postJson('/api/v1/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $use->accessToken->getKey()]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $keep->accessToken->getKey()]);
    }

    public function test_protected_routes_reject_an_anonymous_caller(): void
    {
        $this->getJson('/api/v1/items')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }
}
