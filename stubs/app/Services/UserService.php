<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\CreateUserAction;
use App\DTOs\UserData;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Application service for the User aggregate.
 *
 * The orchestration layer, and the only layer allowed to know the sequence of
 * a use case: it composes repositories and actions, records the audit trail,
 * and returns domain objects. Controllers stay thin because everything here is
 * transport-agnostic — the same method serves an HTTP controller, an Artisan
 * command and a queued job.
 *
 * Dependency Inversion: this class depends on
 * App\Repositories\Contracts\UserRepositoryInterface, never on Eloquent. The
 * concrete binding lives in App\Providers\RepositoryServiceProvider.
 *
 * OWASP A09 (Logging & Alerting Failures): creation and deletion are logged
 * with the acting identity and source IP. Log identifiers only — never a
 * password, token, hash or full request payload.
 */
final class UserService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly CreateUserAction $createUser,
    ) {
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

    public function create(UserData $data, ?int $actorId = null): User
    {
        $user = $this->createUser->execute($data);

        Log::info('user.created', [
            'user_id' => $user->getKey(),
            'actor_id' => $actorId,
            'ip' => request()->ip(),
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes, ?int $actorId = null): User
    {
        // A04: a password may only ever be written hashed, whichever path
        // reaches the repository.
        if (isset($attributes['password'])) {
            $attributes['password'] = Hash::make((string) $attributes['password']);

            Log::info('user.password_changed', [
                'user_id' => $user->getKey(),
                'actor_id' => $actorId,
                'ip' => request()->ip(),
            ]);
        }

        $updated = $this->users->update($user, $attributes);

        Log::info('user.updated', [
            'user_id' => $updated->getKey(),
            'actor_id' => $actorId,
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
}
