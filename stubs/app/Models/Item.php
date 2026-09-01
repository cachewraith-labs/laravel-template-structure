<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The reference aggregate root.
 *
 * Item exists to show the whole vertical slice — request, policy, resource,
 * service, repository, migration, factory, test — and to give API versioning
 * something concrete to break: V2 reshapes an Item's JSON while V1 keeps
 * serving its clients unchanged. Copy this slice for your own domain and then
 * delete it.
 *
 * OWASP A01 (mass assignment): $fillable is an allowlist and deliberately
 * omits user_id. Ownership is set by the service from the authenticated
 * caller, never from the request body — otherwise a client could create or
 * move an item into somebody else's account.
 */
final class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'price_cents',
        'status',
    ];

    /** @var list<string> */
    protected $hidden = [];

    /**
     * A property rather than the casts() method, so the same file works on
     * Laravel 10 (where casts() is not called) as well as 11+.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price_cents' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected static function newFactory(): ItemFactory
    {
        return ItemFactory::new();
    }
}
