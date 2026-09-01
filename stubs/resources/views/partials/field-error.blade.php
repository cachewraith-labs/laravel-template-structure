{{--
    One field's first validation message.

    Usage: @include('partials.field-error', ['field' => 'name'])
--}}
@error($field)
    <p class="field-error" role="alert">{{ $message }}</p>
@enderror
