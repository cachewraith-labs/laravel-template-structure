{{--
    Sign-in form.

    Rendered only when the scaffolded LoginController exists — if your project
    already has laravel/ui, Breeze, Jetstream or Fortify, the installer leaves
    their views and routes alone and never writes this file.

    OWASP A07: the form carries a CSRF token, the password field is never
    repopulated on failure (old('password') is deliberately absent), and the
    error text is the same whether the email was unknown or the password was
    wrong — see LoginController.
--}}
@extends('layouts.app')

@section('title', __('Sign in'))

@section('content')
    <div class="card card-narrow">
        <h1>{{ __('Sign in') }}</h1>

        <form method="POST" action="{{ route('login.store') }}" novalidate>
            @csrf

            <div class="field">
                <label for="email">{{ __('Email') }}</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    maxlength="254"
                    required
                    autocomplete="username"
                    autofocus
                >
                @include('partials.field-error', ['field' => 'email'])
            </div>

            <div class="field">
                <label for="password">{{ __('Password') }}</label>
                {{-- Never value="{{ old('password') }}": it would put the
                     credential into the rendered HTML, browser history and
                     any cache or log that sees the page (A04). --}}
                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                >
                @include('partials.field-error', ['field' => 'password'])
            </div>

            <div class="field field-inline">
                <input id="remember" type="checkbox" name="remember" value="1" @checked(old('remember'))>
                <label for="remember">{{ __('Remember me on this device') }}</label>
            </div>

            <button type="submit" class="btn btn-primary">{{ __('Sign in') }}</button>
        </form>
    </div>
@endsection
