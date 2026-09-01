<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php (Laravel 11+) or RouteServiceProvider (Laravel
| 10) under the prefix "{cachewraith-template.api_version_prefix}/v1" with the
| api middleware group and the route-name prefix "v1." — so no prefix(),
| middleware('api') or name('v1.') is repeated below.
|
| Open/Closed: once v1 is released this file is frozen. Introducing a breaking
| change means routes/api_v2.php and a V2 controller, never an edit here.
|
*/

Route::get('health', static fn (): array => [
    'success' => true,
    'message' => 'ok',
    'errors' => null,
    'code' => 200,
])->name('health');

/*
| Credential exchange. Registered only when Laravel Sanctum is installed
| (composer require laravel/sanctum) and your User model uses
| Laravel\Sanctum\HasApiTokens.
|
| OWASP A07: ratelimit.api:login caps attempts per email+IP. Never expose a
| credential endpoint without a ceiling.
*/
if (class_exists(\Laravel\Sanctum\Sanctum::class)) {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('ratelimit.api:login')
        ->name('login');

    Route::post('logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('logout');
}

/*
| Everything below requires a valid token. Authorisation is still enforced
| per action by the policies — authentication only proves who is calling,
| never what they may do (OWASP A01).
*/
Route::middleware(['auth:sanctum', 'ratelimit.api'])->group(static function (): void {
    Route::apiResource('users', UserController::class);
    Route::apiResource('items', ItemController::class);
});
