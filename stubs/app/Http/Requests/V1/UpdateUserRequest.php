<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates and authorises a user-update request.
 *
 * OWASP A01: the policy is asked about *this* user instance, resolved from the
 * route binding — not from an id in the request body. Trusting a client-supplied
 * identifier here is the textbook broken-access-control bug (IDOR).
 */
final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        if (! $user instanceof User) {
            return false;
        }

        return $this->user()?->can('update', $user) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email:rfc',
                'max:254',
                Rule::unique(User::class, 'email')->ignore($user?->getKey()),
            ],
            'password' => [
                'sometimes',
                'required',
                'string',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised(),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }
}
