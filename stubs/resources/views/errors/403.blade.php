{{--
    Standalone on purpose — this page does not extend layouts.app.

    An error page is rendered from wherever the failure happened, and that is
    often outside the web middleware group: a 404 for an unmatched URL never
    reaches StartSession, and a 503 in maintenance mode is returned by global
    middleware before the session exists at all. A layout that calls @auth or
    reads the session would throw *while rendering the error page*, which
    turns a clean 404 into a 500 (OWASP A10: the error path must not be the
    thing that fails).

    So: no session, no auth, no data. Just the stylesheet, which is a static
    file on this origin.

    OWASP A02: the copy is deliberately generic. An error page is the classic
    place to leak a path, a query or a class name; nothing here interpolates
    anything from the exception.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Not allowed</title>
    <link rel="stylesheet" href="{{ asset('vendor/cachewraith/app.css') }}">
</head>
<body>
<main class="shell">
    <div class="card card-narrow error-page">
        <p class="error-code">403</p>
        <h1>{{ __('Not allowed') }}</h1>
        <p class="muted">{{ __('You do not have permission to view this page.') }}</p>
        <a class="btn btn-primary" href="{{ url('/') }}">{{ __('Go back') }}</a>
    </div>
</main>
</body>
</html>
