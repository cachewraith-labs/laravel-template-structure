<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Binds persistence contracts to their concrete implementations.
 *
 * This is where Dependency Inversion is actually paid for: every consumer type
 * hints App\Repositories\Contracts\*Interface, and this one file decides which
 * class satisfies it. Swapping Eloquent for an HTTP-backed or in-memory
 * implementation is a one-line change here and nowhere else — which is also
 * what makes repositories easy to fake in tests:
 *
 *     $this->app->bind(UserRepositoryInterface::class, InMemoryUserRepository::class);
 *
 * php artisan smn:install appends new bindings directly below the marker
 * comment, so keep it in place if you want that to keep working.
 */
final class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Contract => implementation. Read by the container at registration time.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        // smn-template:bindings
        \App\Repositories\Contracts\UserRepositoryInterface::class => \App\Repositories\Eloquent\EloquentUserRepository::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
