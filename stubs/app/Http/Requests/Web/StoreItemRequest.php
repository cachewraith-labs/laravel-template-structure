<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Models\Item;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and authorises item creation from the Blade form.
 *
 * OWASP A01: authorize() asks ItemPolicy — never "return true", and never
 * "the form only renders for owners". A @can in a template hides a button;
 * it does not stop a crafted POST.
 *
 * OWASP A05 / A06: rules() is an allowlist and every field is bounded. Note
 * that user_id is absent — ownership comes from the authenticated caller in
 * ItemService, so adding a hidden input to the form buys nothing.
 *
 * A failure here redirects back with the old input and an error bag, which is
 * the only visible difference from the V1 API request; the authorisation
 * decision is identical because the domain is the same.
 *
 * The rules differ in exactly one way: the form always posts a status, so it
 * is "required" rather than the API's "sometimes". Everything else is the same
 * allowlist, deliberately restated rather than imported — V1 is a frozen
 * contract, and the day this form gains a field, borrowing its request class
 * would force a change into a released API version.
 */
final class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Item::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],

            // The form posts a decimal; prepareForValidation converts it to
            // the minor units the column stores.
            'price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],

            // A new item starts as a draft or published; "archived" is only
            // reachable through a transition (see ItemService).
            'status' => ['required', Rule::in(['draft', 'published'])],
        ];
    }

    /**
     * The form field is called "price" and carries a decimal, because that is
     * what a person types. The column stores minor units. Money owns the one
     * conversion; an unparseable value is simply not merged, so the rules
     * above reject it instead of a silent 0 reaching the database.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'name' => is_string($this->input('name')) ? trim((string) $this->input('name')) : null,
            'price_cents' => Money::toMinorUnits($this->input('price')),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Report errors against the field the form actually shows.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['price_cents' => 'price'];
    }
}
