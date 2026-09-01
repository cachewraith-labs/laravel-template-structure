<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a credential exchange.
 *
 * OWASP A07 (Authentication Failures): authorize() is a real decision here too
 * — the endpoint is for guests, so a caller that already holds a valid token is
 * refused rather than being allowed to mint more. Brute-force protection is the
 * route's ratelimit.api:login middleware.
 *
 * Note the deliberately loose rules: this is not the place to enforce password
 * complexity. Rejecting a login for a "weak" password tells an attacker that
 * the account exists and what its policy is; complexity is enforced on write,
 * in StoreUserRequest.
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }
}
