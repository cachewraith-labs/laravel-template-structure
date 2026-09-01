@extends('layouts.app')

@section('title', $item->name)

@section('content')
    <div class="page-head">
        <h1>{{ $item->name }}</h1>
        <span class="badge badge-{{ $item->status }}">{{ $item->status }}</span>
    </div>

    <div class="card">
        <dl class="detail">
            <dt>{{ __('Price') }}</dt>
            <dd>{{ App\Support\Money::format($item->price_cents) }}</dd>

            <dt>{{ __('Description') }}</dt>
            {{-- Escaped, and shown with white-space: pre-line rather than
                 {!! nl2br() !!}. Turning a visitor's text into markup is how
                 stored XSS gets shipped (A05). --}}
            <dd class="prose">{{ $item->description ?: __('—') }}</dd>

            <dt>{{ __('Created') }}</dt>
            <dd><time datetime="{{ $item->created_at?->toIso8601String() }}">{{ $item->created_at?->toDayDateTimeString() }}</time></dd>

            <dt>{{ __('Updated') }}</dt>
            <dd><time datetime="{{ $item->updated_at?->toIso8601String() }}">{{ $item->updated_at?->toDayDateTimeString() }}</time></dd>
        </dl>
    </div>

    <div class="form-actions">
        <a class="btn" href="{{ route('items.index') }}">{{ __('Back to items') }}</a>

        @can('update', $item)
            <a class="btn btn-primary" href="{{ route('items.edit', $item) }}">{{ __('Edit') }}</a>
        @endcan

        @can('delete', $item)
            {{-- DELETE via a form, never a link: a GET that destroys data is
                 reachable from any other site's markup and skips CSRF. No
                 onsubmit="confirm(...)" either — the CSP for web routes is
                 script-src 'self' with no 'unsafe-inline'. --}}
            <form method="POST" action="{{ route('items.destroy', $item) }}" class="inline-form">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">{{ __('Delete') }}</button>
            </form>
        @endcan
    </div>
@endsection
