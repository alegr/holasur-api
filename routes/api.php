<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

// Import endpoints
Route::post('/import/{entity}', [ImportController::class, 'import'])
    ->whereIn('entity', ['owners', 'properties', 'customers', 'bookings', 'tasks']);

Route::get('/import/logs', [ImportController::class, 'logs']);

// Data endpoints
Route::get('/properties', [ApiController::class, 'properties']);
Route::get('/bookings', [ApiController::class, 'bookings']);
Route::get('/stats', [ApiController::class, 'stats']);
