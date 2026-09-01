{{--
    The item listing.

    OWASP A01: $items is whatever ItemController got back from
    ItemService::paginateForOwner, which is scoped to the signed-in user by
    the repository contract. This template does no filtering of its own — a
    listing that is "filtered in the view" is a listing that leaks the moment
    someone adds a second view over the same data.
--}}
@extends('layouts.app')

@section('title', __('Items'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Items') }}</h1>

        @can('create', App\Models\Item::class)
            <a class="btn btn-primary" href="{{ route('items.create') }}">{{ __('New item') }}</a>
        @endcan
    </div>

    {{-- No onchange="this.form.submit()" and no script anywhere in this
         scaffold: the Content-Security-Policy for web routes is script-src
         'self' with no 'unsafe-inline', and an inline handler is exactly
         what that forbids. If you add JavaScript, put it in a file under
         public/ or your build output — do not weaken the policy (A02). --}}
    <form method="GET" action="{{ route('items.index') }}" class="filters">
        <label for="status">{{ __('Status') }}</label>
        <select id="status" name="status">
            <option value="">{{ __('All') }}</option>
            @foreach ($statuses as $option)
                <option value="{{ $option }}" @selected($status === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn">{{ __('Filter') }}</button>
    </form>

    <div class="table-wrap">
        <table>
            <caption class="sr-only">{{ __('Your items') }}</caption>
            <thead>
            <tr>
                <th scope="col">{{ __('Name') }}</th>
                <th scope="col">{{ __('Price') }}</th>
                <th scope="col">{{ __('Status') }}</th>
                <th scope="col">{{ __('Updated') }}</th>
                <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($items as $item)
                <tr>
                    <th scope="row"><a href="{{ route('items.show', $item) }}">{{ $item->name }}</a></th>
                    <td class="numeric">{{ App\Support\Money::format($item->price_cents) }}</td>
                    <td><span class="badge badge-{{ $item->status }}">{{ $item->status }}</span></td>
                    <td>
                        <time datetime="{{ $item->updated_at?->toIso8601String() }}">
                            {{ $item->updated_at?->diffForHumans() }}
                        </time>
                    </td>
                    <td class="actions">
                        {{-- @can hides the control. ItemPolicy is asked again
                             for real in the controller and the form request —
                             a hidden button is not an access control (A01). --}}
                        @can('update', $item)
                            <a href="{{ route('items.edit', $item) }}">{{ __('Edit') }}</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="muted">{{ __('No items match this filter.') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $items->links('partials.pagination') }}
@endsection
