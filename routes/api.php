<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\CostController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\BatchCostController;
use App\Http\Controllers\QuickCostController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Import endpoints
Route::post('/import/{entity}', [ImportController::class, 'import'])
    ->whereIn('entity', ['owners', 'properties', 'customers', 'bookings', 'tasks', 'avantio_payments']);

Route::get('/import/logs', [ImportController::class, 'logs']);

// Data endpoints
Route::get('/properties', [ApiController::class, 'properties']);
Route::get('/properties/{id}', [ApiController::class, 'property']);
Route::get('/bookings', [ApiController::class, 'bookings']);
Route::get('/bookings/{id}', [ApiController::class, 'booking']);
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

// Quick cost (simplified inline cost entry)
Route::post('/quick-cost', [QuickCostController::class, 'store']);
Route::get('/quick-cost/recent', [QuickCostController::class, 'recent']);

// Batch costs (monthly cost entry)
Route::get('/batch-costs', [BatchCostController::class, 'batchIndex']);
Route::post('/batch-costs', [BatchCostController::class, 'batchStore']);

// Structural costs
Route::get('/structural-costs', [BatchCostController::class, 'structuralIndex']);
Route::post('/structural-costs', [BatchCostController::class, 'structuralStore']);

// Avantio Payments
Route::get('/avantio-payments', [ApiController::class, 'avantioPayments']);
Route::get('/avantio-payments/summary', [ApiController::class, 'avantioPaymentsSummary']);

// Analytics & Reporting
Route::prefix('analytics')->group(function () {
    Route::get('/property/{id}/profitability', [AnalyticsController::class, 'propertyProfitability']);
    Route::get('/properties/ranking', [AnalyticsController::class, 'propertiesRanking']);
    Route::get('/revenue/by-channel', [AnalyticsController::class, 'revenueByChannel']);
    Route::get('/revenue/by-month', [AnalyticsController::class, 'revenueByMonth']);
    Route::get('/revenue/by-property', [AnalyticsController::class, 'revenueByProperty']);
    Route::get('/costs/summary', [AnalyticsController::class, 'costsSummary']);
    Route::get('/cashflow', [AnalyticsController::class, 'cashflow']);
    Route::get('/kpis', [AnalyticsController::class, 'kpis']);
});

// P&L Reports
Route::prefix('reports')->group(function () {
    Route::get('/pnl/booking/{id}', [ReportController::class, 'bookingPnl']);
    Route::get('/pnl/property/{id}', [ReportController::class, 'propertyPnl']);
    Route::get('/pnl/global', [ReportController::class, 'globalPnl']);
});

Route::get('/exchange-rate', [\App\Http\Controllers\ExchangeRateController::class, 'current']);
