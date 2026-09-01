{{--
    Pagination links, styled by this scaffold's own stylesheet.

    Laravel's bundled paginator views assume Tailwind or Bootstrap classes.
    This scaffold ships neither — no npm, no build step — so it renders its
    own. Passed to the paginator as {{ $items->links('partials.pagination') }};
    Laravel supplies $paginator and $elements.

    Every URL comes from the paginator, which builds it from the current
    request's query string; the values are escaped by {{ }} on the way out.
--}}
@if ($paginator->hasPages())
    <nav class="pagination" aria-label="{{ __('Pagination') }}">
        @if ($paginator->onFirstPage())
            <span class="page is-disabled" aria-disabled="true">{{ __('Previous') }}</span>
        @else
            <a class="page" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="page is-disabled">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="page is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="page" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="page" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>
        @else
            <span class="page is-disabled" aria-disabled="true">{{ __('Next') }}</span>
        @endif
    </nav>
@endif
