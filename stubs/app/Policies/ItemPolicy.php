<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

/**
 * Authorisation rules for the Item aggregate.
 *
 * OWASP A01 (Broken Access Control): ownership is decided from the record's
 * own user_id column compared against the *authenticated* user. Note what is
 * absent — nothing here reads a request parameter. A policy that trusts an id
 * from the body is the textbook IDOR: the caller simply sends someone else's.
 *
 * Laravel discovers this class by name (App\Models\Item ->
 * App\Policies\ItemPolicy). If you move or rename either side, register the
 * pairing explicitly with Gate::policy() in AppServiceProvider — a policy that
 * silently stops being found fails *open*.
 */
final class ItemPolicy
{
    public function viewAny(User $user): bool
    {
        // The listing itself is scoped to the caller by
        // ItemRepository::paginateForOwner, so "may list" is not "may list
        // everyone's".
        return $user->exists;
    }

    public function view(User $user, Item $item): bool
    {
        return $this->owns($user, $item);
    }

    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function update(User $user, Item $item): bool
    {
        // Archived records are read-only: an audit trail nobody can rewrite is
        // worth more than the convenience of editing one.
        return $this->owns($user, $item) && $item->status !== 'archived';
    }

    public function delete(User $user, Item $item): bool
    {
        return $this->owns($user, $item);
    }

    public function restore(User $user, Item $item): bool
    {
        return $this->owns($user, $item);
    }

    /**
     * Permanent deletion is unrecoverable: closed to everyone until a role
     * check is added deliberately.
     */
    public function forceDelete(User $user, Item $item): bool
    {
        return false;
    }

    private function owns(User $user, Item $item): bool
    {
        return $user->exists && (int) $item->user_id === (int) $user->getKey();
    }
}
