{{--
    Flash messages and the error summary.

    Included by the layout, so a page never has to remember to render them and
    a redirect ->with('status', ...) always shows up somewhere.

    Every value is escaped by {{ }}. The messages here come from the
    application (controllers and the validator), never straight from user
    input — but they are escaped anyway, because "this one is safe" is exactly
    the reasoning that puts an XSS into the one that is not (A05).
--}}
@if (session('status'))
    <div class="alert alert-success" role="status">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-error" role="alert">
        <p class="alert-title">{{ __('Please correct the following:') }}</p>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
