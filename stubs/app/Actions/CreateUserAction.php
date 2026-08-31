<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\UserData;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Creates a user.
 *
 * Command pattern, one public method, one job. An action owns a single unit of
 * work that must be identical everywhere it is invoked — HTTP, console,
 * queue, test. Orchestration across several actions belongs in a service; the
 * action itself knows nothing about who called it.
 *
 * OWASP A04 (Cryptographic Failures): hashing lives here, at the single write
 * path, so no caller can persist a plaintext password by forgetting a step.
 * Hash::make uses the application's configured driver (bcrypt or argon2id) —
 * never md5/sha1 or any other fast hash for credentials.
 */
final class CreateUserAction
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    public function execute(UserData $data): User
    {
        if ($data->password === null || $data->password === '') {
            throw new RuntimeException('A password is required to create a user.');
        }

        return DB::transaction(fn (): User => $this->users->create([
            ...$data->toAttributes(),
            'password' => Hash::make($data->password),
        ]));
    }
}
