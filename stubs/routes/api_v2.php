<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V2\ItemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v2
|--------------------------------------------------------------------------
|
| Served at "{cachewraith-template.api_version_prefix}/v2" with the route-name
| prefix "v2.", so v1.users.index and v2.users.index stay distinct names.
| Enable it by adding 'v2' to the versions array in config/cachewraith-template.php.
|
| Only "items" changed shape. Everything else still points at the V1
| controllers on purpose — a version bump is a promise about the endpoints
| that changed, not an instruction to fork the whole surface. Copy a class into
| V2 when, and only when, its contract genuinely differs.
|
*/

Route::get('health', static fn (): array => [
    'success' => true,
    'message' => 'ok',
    'errors' => null,
    'code' => 200,
])->name('health');

if (class_exists(\Laravel\Sanctum\Sanctum::class)) {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('ratelimit.api:login')
        ->name('login');

    Route::post('logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('logout');
}

Route::middleware(['auth:sanctum', 'ratelimit.api'])->group(static function (): void {
    Route::apiResource('users', UserController::class);

    // The one endpoint group that actually changed in v2.
    Route::apiResource('items', ItemController::class);
});
