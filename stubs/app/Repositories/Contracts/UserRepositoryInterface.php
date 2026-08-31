<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Persistence contract for the User aggregate root.
 *
 * Repository pattern. Two reasons it is here rather than calling Eloquent from
 * a service: the persistence mechanism becomes swappable (Dependency
 * Inversion — nothing outside App\Repositories\Eloquent may name a query
 * builder), and it becomes trivially fakeable in tests.
 *
 * Interface Segregation: one interface per aggregate root. Do not grow this
 * into a generic CrudRepositoryInterface shared by every model — consumers
 * would then depend on methods they never call.
 */
interface UserRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    /**
     * @return Collection<int, User>
     */
    public function all(): Collection;

    public function findById(int|string $id): ?User;

    public function findByEmail(string $email): ?User;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): User;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User;

    public function delete(User $user): bool;
}
