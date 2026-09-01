<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Services\ItemService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The landing page for a signed-in visitor.
 *
 * Thin on purpose: it authorises, asks a service for a bounded slice, and
 * renders. If this page later needs "items created this week" or a count by
 * status, that query belongs in ItemRepository behind its interface and the
 * sequencing in ItemService — not here, and not in the template.
 *
 * OWASP A01: the recent list comes from paginateForOwner, which is scoped to
 * the caller by the contract itself. There is no unscoped listing method on
 * ItemRepositoryInterface for this controller to reach for.
 */
final class DashboardController extends Controller
{
    private const RECENT_LIMIT = 5;

    public function __construct(private readonly ItemService $items)
    {
    }

    public function __invoke(Request $request): View
    {
        Gate::authorize('viewAny', Item::class);

        return view('dashboard', [
            'recent' => $this->items->paginateForOwner($request->user(), self::RECENT_LIMIT),
        ]);
    }
}
