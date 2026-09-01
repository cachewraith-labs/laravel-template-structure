@extends('layouts.app')

@section('title', __('Edit :name', ['name' => $item->name]))

@section('content')
    <div class="card card-narrow">
        <h1>{{ __('Edit item') }}</h1>

        {{-- The item is identified by the URL, which is what ItemPolicy is
             asked about. There is no id in the body for a visitor to swap
             (A01 / IDOR). --}}
        <form method="POST" action="{{ route('items.update', $item) }}" novalidate>
            @csrf
            @method('PUT')

            @include('items._form', ['item' => $item])

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">{{ __('Save changes') }}</button>
                <a class="btn" href="{{ route('items.show', $item) }}">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
@endsection
