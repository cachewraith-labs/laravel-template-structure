<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the Blade sign-in form.
 *
 * A sibling of App\Http\Requests\V1\LoginRequest rather than a reuse of it.
 * V1 is a frozen API contract — the day the web form gains a field, borrowing
 * its request class would force a change into a released API version. Same
 * rules today, different lifecycles, so different files.
 *
 * OWASP A07: the rules are deliberately loose. Rejecting a sign-in because the
 * submitted password is "too weak" tells an attacker both that the account
 * exists and what its policy is. Complexity is enforced where passwords are
 * written, in StoreUserRequest.
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route also carries the guest middleware; this is the same
        // decision made where it cannot be routed around.
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
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }
}
