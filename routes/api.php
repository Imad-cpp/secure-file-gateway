<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\FileIngestionController;
use App\Http\Controllers\Api\V1\FileMetadataController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')
        ->middleware('throttle:auth')
        ->group(function (): void {
            Route::post('/register', RegisterController::class);
            Route::post('/login', LoginController::class);
        });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', LogoutController::class);
        Route::get('/me', MeController::class);

        Route::post('/files', FileIngestionController::class)
            ->middleware('throttle:uploads');
        Route::get('/files', [FileMetadataController::class, 'index']);
        Route::get('/files/{file}', [FileMetadataController::class, 'show']);
    });
});
