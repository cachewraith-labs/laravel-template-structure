<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php (Laravel 11+) or RouteServiceProvider (Laravel
| 10) under the prefix "{smn-template.api_version_prefix}/v1" with the api
| middleware group — so no prefix() or middleware('api') is repeated below.
|
| Open/Closed: once v1 is released this file is frozen. Introducing v2 is:
|
|     cp routes/api/v1.php routes/api/v2.php
|     # point v2.php at App\Http\Controllers\Api\V2\*
|     # add 'v2' to the versions array in config/smn-template.php
|
| v1 controllers, requests and resources stay untouched, so existing clients
| keep working while new ones move over at their own pace.
|
*/

Route::get('health', static fn (): array => [
    'success' => true,
    'message' => 'ok',
    'errors' => null,
    'code' => 200,
])->name('api.v1.health');

/*
| Credential exchange. Registered only when Laravel Sanctum is installed
| (composer require laravel/sanctum) and your User model uses
| Laravel\Sanctum\HasApiTokens.
|
| OWASP A07: throttle:5,1 caps this route at five attempts per minute per
| client, on top of the per-credential limiter inside AuthController. Never
| expose a credential endpoint without both.
*/
if (class_exists(\Laravel\Sanctum\Sanctum::class)) {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('api.v1.login');

    Route::post('logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('api.v1.logout');
}

/*
| Everything below requires a valid token. Authorisation is still enforced
| per action by UserPolicy — authentication only proves who is calling, never
| what they may do (OWASP A01).
*/
Route::middleware('auth:sanctum')->group(static function (): void {
    Route::apiResource('users', UserController::class);
});
