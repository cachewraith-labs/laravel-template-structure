<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Immutable transport object for user input.
 *
 * Chosen over passing the request (or a bare array) into the domain: an array
 * has no contract, and a FormRequest drags the whole HTTP layer into services
 * and actions. A readonly class gives named, typed fields that are validated
 * once at the boundary and cannot be mutated afterwards.
 *
 * Only ever built from already-validated input — see the FormRequest classes
 * under App\Http\Requests\Api. Never build one straight from $request->all()
 * (OWASP A01: mass assignment through an unvalidated payload).
 *
 * Note: the installer swaps this file for a spatie/laravel-data version when
 * that package is installed.
 */
final readonly class UserData
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $password = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            name: (string) ($attributes['name'] ?? ''),
            email: (string) ($attributes['email'] ?? ''),
            password: isset($attributes['password']) ? (string) $attributes['password'] : null,
        );
    }

    public static function fromRequest(FormRequest $request): self
    {
        return self::fromArray($request->validated());
    }

    /**
     * Attributes safe to hand to the persistence layer.
     *
     * The password is deliberately excluded: hashing is the responsibility of
     * the action that writes it (OWASP A04), so a plaintext value must never
     * reach a repository through this method.
     *
     * @return array<string, string>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
