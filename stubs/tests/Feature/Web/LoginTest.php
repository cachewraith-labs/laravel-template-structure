<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature tests for the Blade sign-in flow.
 *
 * These assert the properties, not the happy path: that the two failure modes
 * are indistinguishable, that the session id actually rotates, and that the
 * form is unusable without a CSRF token. A test that only proves a correct
 * password works would pass against every one of those bugs.
 */
final class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sign_in_page_renders_for_a_guest(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in', escape: false);
    }

    public function test_a_signed_in_visitor_is_redirected_away_from_the_sign_in_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/login')
            ->assertRedirect();
    }

    /**
     * OWASP A07: session fixation. An id captured before authentication must
     * not survive it, or an attacker who planted the cookie is now signed in
     * as the victim.
     */
    public function test_the_session_id_is_regenerated_on_sign_in(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse-battery-staple')]);

        // Sampled from inside the request, at the moment the guard fires
        // Login. Comparing against an id captured before the request would
        // pass whether or not regenerate() is ever called, because the
        // session id is generated fresh for a request that carries no cookie.
        $atLogin = null;
        Event::listen(Login::class, static function () use (&$atLogin): void {
            $atLogin = session()->getId();
        });

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($atLogin, 'The Login event never fired.');
        $this->assertNotSame($atLogin, session()->getId(), 'The session id survived sign-in.');
    }

    /**
     * OWASP A07: account enumeration. An unknown address and a wrong password
     * must produce the same answer, or the form becomes a directory of who
     * has an account here.
     */
    public function test_an_unknown_email_and_a_wrong_password_are_indistinguishable(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse-battery-staple')]);
        $message = 'These credentials do not match our records.';

        $this->from('/login')
            ->post('/login', ['email' => 'nobody@example.com', 'password' => 'whatever-they-typed'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => $message]);

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'not-the-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => $message]);

        $this->assertGuest();
    }

    public function test_the_submitted_password_is_never_echoed_back_into_the_form(): void
    {
        $this->from('/login')->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'sup3r-s3cret-value',
        ]);

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('sup3r-s3cret-value');
    }

    /**
     * OWASP A01: the CSRF token is the only thing standing between a
     * state-changing form and any other site that can make a browser submit
     * to it. Every scaffolded form must carry @csrf.
     *
     * The assertion is that the token is *in the markup*, not that a POST
     * without one is refused: VerifyCsrfToken short-circuits whenever the
     * application is running its own test suite, so asserting a 419 here
     * would be asserting against a middleware that is switched off. What is
     * ours to get wrong is forgetting the directive.
     */
    public function test_every_state_changing_form_carries_a_csrf_token(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="_token"', escape: false);

        $this->actingAs(User::factory()->create())
            ->get('/items/create')
            ->assertOk()
            ->assertSee('name="_token"', escape: false);
    }

    public function test_signing_out_requires_a_post_and_clears_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/logout')->assertStatus(405);

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_a_guest_is_sent_to_the_sign_in_page_rather_than_the_content(): void
    {
        $this->get('/items')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    /**
     * OWASP A02: the hardening headers apply to HTML as well as JSON, but
     * under the web profile — the API's "default-src 'none'" would block the
     * page's own stylesheet and every form submission on it.
     */
    public function test_html_responses_carry_the_web_security_header_profile(): void
    {
        $response = $this->get('/login')->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
