<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreItemRequest;
use App\Http\Requests\Web\UpdateItemRequest;
use App\Models\Item;
use App\Services\ItemService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Blade front door onto the Item aggregate — the reference web slice.
 *
 * Read this next to App\Http\Controllers\Api\V1\ItemController. They are the
 * same seven decisions made twice, and the *only* thing that differs is the
 * transport: one returns a JsonResource through the ApiResponse envelope, the
 * other returns a view or a redirect. Everything that decides what may happen
 * — ItemPolicy, ItemService, ItemRepository, the status-transition rule — is
 * shared, because a rule enforced in two places is a rule that will diverge,
 * and the half that drifts is the half nobody tested.
 *
 * That sharing is the whole argument for the layering. Adding a second front
 * door cost a controller, two form requests and some templates; it cost no
 * business logic at all.
 *
 * OWASP A01: index() never accepts an owner parameter — the listing is scoped
 * to $request->user() by the service, so there is no "?user_id=" to walk.
 * show/edit/update/destroy resolve the Item from the route binding and
 * authorise against that instance. The @can directives in the templates hide
 * buttons; they are cosmetic, and every one of them has a real policy call
 * behind it here or in the form request.
 *
 * OWASP A06: the page size is a constant read from config, not a query
 * parameter, so there is no ?per_page=1000000 to clamp.
 */
final class ItemController extends Controller
{
    /** @var list<string> */
    private const FILTERABLE_STATUSES = ['draft', 'published', 'archived'];

    public function __construct(private readonly ItemService $items)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Item::class);

        // An allowlist, not a passthrough. The repository parameterises the
        // value either way (A05), but an unknown status should mean "no
        // filter", never "whatever the visitor typed".
        $status = $request->string('status')->toString();
        $status = in_array($status, self::FILTERABLE_STATUSES, true) ? $status : null;

        return view('items.index', [
            'items' => $this->items->paginateForOwner(
                $request->user(),
                (int) config('cachewraith-template.pagination.per_page', 15),
                $status,
            )->withQueryString(),
            'status' => $status,
            'statuses' => self::FILTERABLE_STATUSES,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Item::class);

        return view('items.create');
    }

    public function store(StoreItemRequest $request): RedirectResponse
    {
        $item = $this->items->create(
            $request->user(),
            $request->validated(),
            $request->user()->getKey(),
        );

        return redirect()
            ->route('items.show', $item)
            ->with('status', __('Item created.'));
    }

    public function show(Item $item): View
    {
        Gate::authorize('view', $item);

        return view('items.show', ['item' => $item]);
    }

    public function edit(Item $item): View
    {
        Gate::authorize('update', $item);

        return view('items.edit', ['item' => $item]);
    }

    public function update(UpdateItemRequest $request, Item $item): RedirectResponse
    {
        try {
            $this->items->update($item, $request->validated(), $request->user()->getKey());
        } catch (HttpException $e) {
            // ItemService refuses an illegal status transition with a 422,
            // which is the right answer for the API. On the web the same
            // refusal belongs back on the form beside the field that caused
            // it. Translating it is transport work, which is this class's
            // job; re-deciding it here would duplicate the domain rule.
            if ($e->getStatusCode() !== 422) {
                throw $e;
            }

            return back()
                ->withInput()
                ->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('items.show', $item)
            ->with('status', __('Item updated.'));
    }

    public function destroy(Request $request, Item $item): RedirectResponse
    {
        Gate::authorize('delete', $item);

        $this->items->delete($item, $request->user()->getKey());

        return redirect()
            ->route('items.index')
            ->with('status', __('Item deleted.'));
    }
}
