<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImageController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('abilities:tokens:revoke');
    Route::get('/user', [AuthController::class, 'user'])->middleware('abilities:user:read');

    Route::get('/images', [ImageController::class, 'index'])
        ->middleware('abilities:images:read')
        ->name('images.index');
    Route::post('/images', [ImageController::class, 'store'])
        ->middleware(['abilities:images:write', 'throttle:uploads'])
        ->name('images.store');
    Route::get('/images/{image}', [ImageController::class, 'show'])
        ->middleware('abilities:images:read')
        ->name('images.show');
    Route::delete('/images/{image}', [ImageController::class, 'destroy'])
        ->middleware('abilities:images:write')
        ->name('images.destroy');
});
