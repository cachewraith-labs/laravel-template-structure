@extends('layouts.app')

@section('title', __('Dashboard'))

@section('content')
    <h1>{{ __('Dashboard') }}</h1>

    <p class="lede">
        {{ __('Signed in as :name.', ['name' => auth()->user()->name]) }}
    </p>

    <div class="card">
        <div class="card-head">
            <h2>{{ __('Recent items') }}</h2>
            <a class="btn" href="{{ route('items.index') }}">{{ __('View all') }}</a>
        </div>

        @forelse ($recent as $item)
            <div class="row">
                <a href="{{ route('items.show', $item) }}">{{ $item->name }}</a>
                <span class="badge badge-{{ $item->status }}">{{ $item->status }}</span>
            </div>
        @empty
            <p class="muted">{{ __('Nothing here yet.') }}</p>

            @can('create', App\Models\Item::class)
                <a class="btn btn-primary" href="{{ route('items.create') }}">{{ __('Create the first one') }}</a>
            @endcan
        @endforelse
    </div>
@endsection
