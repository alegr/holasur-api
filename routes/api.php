<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\CostController;
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

// Cost tracking
Route::get('/cost-categories', [CostController::class, 'costCategories']);

Route::get('/suppliers', [CostController::class, 'supplierIndex']);
Route::post('/suppliers', [CostController::class, 'supplierStore']);

Route::get('/purchases', [CostController::class, 'purchaseIndex']);
Route::post('/purchases', [CostController::class, 'purchaseStore']);
Route::get('/purchases/{id}', [CostController::class, 'purchaseShow']);
Route::put('/purchases/{id}', [CostController::class, 'purchaseUpdate']);

Route::get('/expenses', [CostController::class, 'expenseIndex']);
Route::post('/expenses', [CostController::class, 'expenseStore']);
Route::get('/expenses/{id}', [CostController::class, 'expenseShow']);
Route::put('/expenses/{id}', [CostController::class, 'expenseUpdate']);
