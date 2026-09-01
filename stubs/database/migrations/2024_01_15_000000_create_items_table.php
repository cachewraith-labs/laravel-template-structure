<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migrations are this project's schema history — Laravel's equivalent of
 * Alembic. Every schema change is a new file; none is edited after it has run
 * anywhere but your own machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->id();

            // Ownership is a column, not a convention: ItemPolicy reads it and
            // the repository scopes every listing by it (OWASP A01).
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 255);
            $table->text('description')->nullable();

            // Money as an integer of minor units. A float would accumulate
            // rounding error, and DECIMAL casts back to a string in PHP.
            $table->unsignedInteger('price_cents')->default(0);

            $table->string('status', 32)->default('draft');
            $table->timestamps();

            // Every filter the repository actually issues gets an index; an
            // unindexed scope turns a listing into a full table scan.
            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
