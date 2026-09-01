<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Business logic for the User aggregate.
 *
 * The only layer allowed to know the sequence of a use case: it drives the
 * repository, applies the rules that are not about persistence, and records
 * the audit trail. Controllers stay thin because everything here is
 * transport-agnostic — the same method serves an HTTP controller, an Artisan
 * command and a queued job.
 *
 * Dependency Inversion: this class depends on UserRepositoryInterface, never
 * on Eloquent. The concrete binding lives in RepositoryServiceProvider, which
 * is what makes the persistence layer swappable and fakeable.
 *
 * OWASP A04 (Cryptographic Failures): hashing happens here, on the single
 * write path, so no caller can persist a plaintext password by forgetting a
 * step. Hash::make uses the application's configured driver (bcrypt or
 * argon2id) — never md5/sha1 or any other fast hash for credentials.
 *
 * OWASP A09 (Logging & Alerting Failures): writes are logged with the acting
 * identity and source IP. Log identifiers only — never a password, token,
 * hash or full request payload.
 */
final class UserService
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->users->paginate($perPage);
    }

    public function findById(int|string $id): ?User
    {
        return $this->users->findById($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->users->findByEmail($email);
    }

    /**
     * @param  array<string, mixed>  $attributes  Already validated by a FormRequest.
     */
    public function create(array $attributes, ?int $actorId = null): User
    {
        $attributes = $this->hashPassword($attributes);

        $user = DB::transaction(fn (): User => $this->users->create($attributes));

        Log::info('user.created', [
            'user_id' => $user->getKey(),
            'actor_id' => $actorId,
            'ip' => request()->ip(),
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes  Already validated by a FormRequest.
     */
    public function update(User $user, array $attributes, ?int $actorId = null): User
    {
        $hadPassword = array_key_exists('password', $attributes);
        $attributes = $this->hashPassword($attributes);

        $updated = DB::transaction(fn (): User => $this->users->update($user, $attributes));

        if ($hadPassword) {
            Log::info('user.password_changed', [
                'user_id' => $updated->getKey(),
                'actor_id' => $actorId,
                'ip' => request()->ip(),
            ]);
        }

        Log::info('user.updated', [
            'user_id' => $updated->getKey(),
            'actor_id' => $actorId,
            // Keys only. The values are the payload we are careful not to log.
            'changed' => array_keys($attributes),
            'ip' => request()->ip(),
        ]);

        return $updated;
    }

    public function delete(User $user, ?int $actorId = null): bool
    {
        $userId = $user->getKey();
        $deleted = $this->users->delete($user);

        Log::warning('user.deleted', [
            'user_id' => $userId,
            'actor_id' => $actorId,
            'ip' => request()->ip(),
        ]);

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function hashPassword(array $attributes): array
    {
        if (isset($attributes['password'])) {
            $attributes['password'] = Hash::make((string) $attributes['password']);
        }

        // Never persist the confirmation field, and never let it reach a log.
        unset($attributes['password_confirmation']);

        return $attributes;
    }
}
