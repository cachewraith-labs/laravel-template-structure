<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local development data.
 *
 * OWASP A02 / A07 (default credentials): this seeder refuses to run in
 * production, and the demo password comes from the environment rather than
 * being a literal in version control. A seeded "password" account that reaches
 * a production database is a breach with a changelog entry.
 */
final class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('UserSeeder skipped: refusing to seed demo accounts in production.');

            return;
        }

        $password = (string) env('SEED_USER_PASSWORD', 'ChangeMe!Locally1');

        $demo = User::query()->firstOrCreate(
            ['email' => 'demo@example.test'],
            [
                'name' => 'Demo User',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        Item::factory()->count(5)->published()->ownedBy($demo)->create();
        Item::factory()->count(2)->draft()->ownedBy($demo)->create();

        // A second account, so an authorisation test has someone to be denied
        // as. Ownership bugs are invisible when the fixture has one user.
        $other = User::query()->firstOrCreate(
            ['email' => 'other@example.test'],
            [
                'name' => 'Other User',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        Item::factory()->count(3)->ownedBy($other)->create();
    }
}
