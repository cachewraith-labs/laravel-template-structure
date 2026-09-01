@extends('layouts.app')

@section('title', __('New item'))

@section('content')
    <div class="card card-narrow">
        <h1>{{ __('New item') }}</h1>

        <form method="POST" action="{{ route('items.store') }}" novalidate>
            @csrf

            @include('items._form', ['item' => null])

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">{{ __('Create item') }}</button>
                <a class="btn" href="{{ route('items.index') }}">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
@endsection
