<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Eloquent-backed implementation of {@see UserRepositoryInterface}.
 *
 * Single Responsibility: persistence only. No authorisation, no logging, no
 * business rules, no hashing — those belong to policies, services and actions
 * respectively. If you find yourself writing an "if" about business meaning in
 * here, it belongs one layer up.
 *
 * OWASP A05 (Injection): every value below reaches the database as a bound
 * parameter through the query builder. Do not introduce DB::raw(), whereRaw()
 * or string-concatenated SQL here; if a raw expression is genuinely
 * unavoidable, pass user input through the bindings argument, never inline it.
 */
final class EloquentUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly User $model)
    {
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->newQuery()
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, User>
     */
    public function all(): Collection
    {
        return $this->model->newQuery()->get();
    }

    public function findById(int|string $id): ?User
    {
        return $this->model->newQuery()->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->newQuery()->where('email', $email)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): User
    {
        return $this->model->newQuery()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        $user->fill($attributes)->save();

        return $user->refresh();
    }

    public function delete(User $user): bool
    {
        return (bool) $user->delete();
    }
}
