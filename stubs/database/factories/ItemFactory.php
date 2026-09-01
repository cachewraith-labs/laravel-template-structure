<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
final class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'price_cents' => fake()->numberBetween(0, 500_00),
            'status' => fake()->randomElement(['draft', 'published', 'archived']),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => 'published']);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => 'draft']);
    }

    /**
     * Ownership is the axis every ItemPolicy test turns on, so make it easy to
     * state explicitly rather than relying on the default factory user.
     */
    public function ownedBy(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }
}
