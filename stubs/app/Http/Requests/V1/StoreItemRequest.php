<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and authorises item creation.
 *
 * OWASP A01: authorize() asks ItemPolicy — never "return true". Note that
 * user_id is absent from rules(): ownership comes from the authenticated
 * caller in ItemService, so a client cannot create an item in someone else's
 * account by adding a field.
 *
 * OWASP A05 / A06: rules() is an allowlist and every field is bounded. An
 * unbounded string field is a storage-exhaustion vector and, once it reaches a
 * template or a log viewer, an injection surface.
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

            // Minor units, integer. Bounded on both ends: a negative price is
            // a refund the domain never agreed to.
            'price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],

            // A new item starts life as a draft or published; "archived" is
            // only reachable through a transition (see ItemService).
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }
}
