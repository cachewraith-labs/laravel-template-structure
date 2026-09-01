{{--
    The shared create/edit fields.

    One partial rather than two near-identical forms, so a new field is added
    once and cannot go missing from the other page. The caller supplies
    $item (an Item, or null when creating).

    OWASP A01: there is no hidden user_id input, and adding one would achieve
    nothing — Item::$fillable omits the column and ItemService sets ownership
    from the authenticated caller. Mass assignment is closed at the model, not
    at the template.
--}}
{{-- Creating offers draft and published; "archived" is only reachable as a
     transition on an existing item, which is what StoreItemRequest and
     UpdateItemRequest allow respectively. The list here mirrors those rules
     so the form does not offer a value the request will reject — it does not
     replace them. --}}
@php($statuses = $item === null ? ['draft', 'published'] : ['draft', 'published', 'archived'])

<div class="field">
    <label for="name">{{ __('Name') }}</label>
    <input
        id="name"
        type="text"
        name="name"
        value="{{ old('name', $item?->name) }}"
        maxlength="255"
        required
        autofocus
    >
    @include('partials.field-error', ['field' => 'name'])
</div>

<div class="field">
    <label for="description">{{ __('Description') }}</label>
    <textarea id="description" name="description" rows="5" maxlength="5000">{{ old('description', $item?->description) }}</textarea>
    @include('partials.field-error', ['field' => 'description'])
</div>

<div class="field">
    <label for="price">{{ __('Price') }} ({{ config('cachewraith-template.currency', 'USD') }})</label>
    {{-- The form speaks decimals; the column stores integer minor units.
         App\Support\Money owns the single conversion, in the form request. --}}
    <input
        id="price"
        type="text"
        inputmode="decimal"
        name="price"
        value="{{ old('price', $item ? App\Support\Money::toDecimal($item->price_cents) : '') }}"
        placeholder="0.00"
        required
    >
    @include('partials.field-error', ['field' => 'price_cents'])
</div>

<div class="field">
    <label for="status">{{ __('Status') }}</label>
    <select id="status" name="status" required>
        @foreach ($statuses as $option)
            <option value="{{ $option }}" @selected(old('status', $item?->status ?? 'draft') === $option)>{{ $option }}</option>
        @endforeach
    </select>
    @include('partials.field-error', ['field' => 'status'])

    <p class="hint">
        {{ __('An item may move draft → published → archived, and never back. ItemService enforces that; this list only offers the vocabulary.') }}
    </p>
</div>
