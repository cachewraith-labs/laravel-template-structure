<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ItemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web UI
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php (Laravel 11+) or RouteServiceProvider (Laravel
| 10) under the "web" middleware group and the optional URL prefix
| "cachewraith-template.web.prefix" — so no middleware('web') is repeated
| below.
|
| The route names are deliberately unprefixed — "login", "dashboard",
| "items.index". Laravel's own Authenticate middleware redirects a guest to
| route('login'), and RedirectIfAuthenticated and every package that assumes
| the convention look for exactly these names; a "web." prefix would break
| all of them for no gain. If one of these names is already taken in your
| routes/web.php, rename it here — this file is yours once it is generated.
|
| This file is separate from routes/web.php, which belongs to your application
| and is never touched by the installer. Delete this file and the scaffolded
| UI is gone; nothing else depends on it.
|
| Unlike the API there is no version prefix here, and that is deliberate.
| Versioning is a contract with clients you cannot deploy — it buys you
| nothing when the only consumer is a browser you ship the HTML to. Change the
| page, ship it, done.
|
*/

/*
| Credential routes, registered only when the scaffolded LoginController
| exists. cachewraith:install skips generating it when the application already
| has auth scaffolding (laravel/ui, Breeze, Jetstream, Fortify or its own
| app/Http/Controllers/Auth), in which case those routes stay theirs and the
| guarded routes below simply use whatever "login" route they defined.
|
| Same guard style as the Sanctum block in routes/api_v1.php.
*/
if (class_exists(LoginController::class)) {
    Route::middleware('guest')->group(static function (): void {
        Route::get('login', [LoginController::class, 'create'])->name('login');

        // OWASP A07: the same email+IP bucket the API's login endpoint uses.
        // A sign-in form without a ceiling is a credential-stuffing target
        // whether it answers in HTML or in JSON.
        Route::post('login', [LoginController::class, 'store'])
            ->middleware('ratelimit.api:login')
            ->name('login.store');
    });

    // POST, never GET: a GET logout is triggerable from an <img> tag on any
    // other site, and CSRF protection does not apply to it.
    Route::post('logout', [LoginController::class, 'destroy'])
        ->middleware('auth')
        ->name('logout');
}

/*
| Everything below requires a signed-in session. Authorisation is still
| enforced per action by the policies — authentication only proves who is
| asking, never what they may do (OWASP A01).
*/
Route::middleware('auth')->group(static function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('items', ItemController::class);
});
