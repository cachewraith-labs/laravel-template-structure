<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Models\Item;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and authorises an item update from the Blade form.
 *
 * OWASP A01: the policy is asked about the Item resolved from the *route
 * binding*. Reading an id out of the form body and loading it here is how
 * IDOR gets written — a hidden field is the easiest thing in the world for a
 * visitor to change. The binding is the only identifier this class trusts.
 *
 * "archived" is an accepted target status, but whether this particular item
 * may reach it is ItemService's decision — validation knows the vocabulary,
 * the domain knows the grammar.
 *
 * Every field is "required" here where the V1 API request says "sometimes",
 * and that difference is deliberate rather than an oversight: the API accepts
 * a PATCH carrying one field, while the edit form always posts the whole
 * record. Treating an absent field as "leave it alone" would mean a form that
 * loses a value silently whenever an input fails to submit.
 */
final class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('item');

        if (! $item instanceof Item) {
            return false;
        }

        return $this->user()?->can('update', $item) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
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
