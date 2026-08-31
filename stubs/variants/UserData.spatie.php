<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Foundation\Http\FormRequest;
use Spatie\LaravelData\Data;

/**
 * Immutable transport object for user input, backed by spatie/laravel-data.
 *
 * Same contract as the plain readonly variant this file replaced: named, typed,
 * immutable fields carrying already-validated input between the HTTP boundary
 * and the domain. spatie/laravel-data adds casting, wrapping and
 * request-to-object mapping on top.
 *
 * Only ever built from validated input (OWASP A01: never from $request->all()).
 */
final class UserData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $password = null,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        return self::from($validated);
    }

    /**
     * Attributes safe to hand to the persistence layer.
     *
     * The password is deliberately excluded: hashing is the responsibility of
     * the action that writes it (OWASP A04).
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
