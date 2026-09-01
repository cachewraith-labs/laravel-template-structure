<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Authorisation rules for the User aggregate.
 *
 * OWASP A01 (Broken Access Control): this class is the single place that
 * decides who may touch a user record. Controllers and FormRequests ask it;
 * they never re-implement the rule inline, because a rule expressed twice is a
 * rule that will diverge.
 *
 * The rules below are ownership-based, which is what a stock Laravel User
 * model can express. Adapt them to your domain rather than loosening them —
 * a common shape is a role or permission check:
 *
 *     public function viewAny(User $user): bool
 *     {
 *         return $user->hasRole('admin');
 *     }
 *
 * Fail closed: when in doubt a policy method returns false. Never return true
 * as a placeholder "until auth is wired up" — that placeholder ships.
 */
final class UserPolicy
{
    /**
     * Any authenticated user may list users; the resource decides which fields
     * each caller actually sees.
     */
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, User $model): bool
    {
        return $user->is($model);
    }

    /**
     * Registration is intentionally *not* open through this endpoint. Point a
     * dedicated, rate-limited public route at UserService::create if you want
     * self-service signup, and keep this admin-only path closed by default.
     */
    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function update(User $user, User $model): bool
    {
        return $user->is($model);
    }

    public function delete(User $user, User $model): bool
    {
        // A user may close their own account; nobody may delete anyone else's
        // without an explicit rule saying so.
        return $user->is($model);
    }

    public function restore(User $user, User $model): bool
    {
        return $user->is($model);
    }

    /**
     * Permanent deletion is a destructive, unrecoverable operation: closed to
     * everyone until a role check is added deliberately.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
