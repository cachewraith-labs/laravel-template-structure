<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and authorises an item update.
 *
 * OWASP A01: the policy is asked about the Item resolved from the *route
 * binding*. Reading an id out of the body and loading it here is how IDOR gets
 * written; the binding is the only identifier this class trusts.
 *
 * "archived" is accepted as a target status, but whether this particular item
 * may reach it is ItemService's decision — validation knows the vocabulary,
 * the domain knows the grammar.
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
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price_cents' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000000'],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'published', 'archived'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }
}
