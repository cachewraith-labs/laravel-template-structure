<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates and authorises a user-creation request.
 *
 * OWASP A01 (Broken Access Control): authorize() delegates to UserPolicy and
 * returns a real decision. "return true" in a FormRequest is an unauthenticated
 * write endpoint waiting to happen — if an endpoint really is public, say so in
 * a comment and gate it in the route instead.
 *
 * OWASP A05 (Injection) / A06 (Insecure Design): rules() is an allowlist. Only
 * keys named here reach validated(), so an attacker-supplied "is_admin" or
 * "email_verified_at" is dropped before it can reach a repository.
 */
final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:254',
                Rule::unique(User::class, 'email'),
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    // Checks the password against the k-anonymity Have I Been
                    // Pwned range API. Drop this line if outbound HTTP is not
                    // available from your application servers.
                    ->uncompromised(),
            ],
        ];
    }

    /**
     * Normalise before validating so the uniqueness rule cannot be bypassed by
     * casing or padding ("Admin@example.com " vs "admin@example.com").
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }
}
