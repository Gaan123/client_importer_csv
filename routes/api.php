<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientsController;
use App\Http\Controllers\Api\ImportsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);

    // Client import endpoint
    Route::post('/clients/import', [ClientsController::class, 'import']);

    // Import management endpoints
    Route::get('/imports', [ImportsController::class, 'index']);
    Route::get('/imports/{import}', [ImportsController::class, 'show']);
    Route::delete('/imports/{import}', [ImportsController::class, 'destroy']);
});
