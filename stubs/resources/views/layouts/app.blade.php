{{--
    The one layout every scaffolded page extends.

    Template Method in Blade's own idiom: this file fixes the order of the
    document — head, banner, flash messages, content, footer — and the @yield
    slots are the steps a page fills in. A page never repeats the chrome, so
    the chrome can be hardened in one place.

    OWASP A05 (Injection / XSS): every interpolation on every scaffolded page
    uses {{ }}, which escapes. There is not one {!! !!} in this scaffold, and
    adding one is a decision to review — it is how an item named
    <script>fetch(...)</script> stops being text and starts being code.

    OWASP A02: the stylesheet is served from this application's own origin, so
    the Content-Security-Policy stays "self" with no 'unsafe-inline' and no
    third-party host to trust. There is no build step: cachewraith:install
    copies public/vendor/cachewraith/app.css and it is served as-is. Swap in
    Tailwind or your own pipeline whenever you like — nothing here depends on
    this file's classes.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Needed by any JavaScript that posts back; the forms below use @csrf. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- A05: a page that renders a visitor's data must not be indexed or
         prefetched by a shared crawler. --}}
    <meta name="robots" content="noindex, nofollow">

    <title>@yield('title', config('app.name', 'Laravel'))</title>

    <link rel="stylesheet" href="{{ asset('vendor/cachewraith/app.css') }}">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<header class="masthead">
    <div class="shell masthead-inner">
        <a class="brand" href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}">
            {{ config('app.name', 'Laravel') }}
        </a>

        <nav class="nav" aria-label="Primary">
            @auth
                <a href="{{ route('dashboard') }}" @class(['is-current' => request()->routeIs('dashboard')])>Dashboard</a>
                <a href="{{ route('items.index') }}" @class(['is-current' => request()->routeIs('items.*')])>Items</a>

                <span class="who">{{ auth()->user()->name }}</span>

                {{-- A07: sign-out is a POST carrying a CSRF token. A GET
                     logout can be fired from an <img> on any other site. --}}
                <form method="POST" action="{{ route('logout') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="btn btn-link">Sign out</button>
                </form>
            @else
                @if (Route::has('login'))
                    <a href="{{ route('login') }}">Sign in</a>
                @endif
            @endauth
        </nav>
    </div>
</header>

<main id="main" class="shell">
    @include('partials.alerts')

    @yield('content')
</main>

<footer class="footer">
    <div class="shell">
        <p>Scaffolded by cachewraith/laravel-template-structure — replace this layout with your own.</p>
    </div>
</footer>
</body>
</html>
