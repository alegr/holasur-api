<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CostController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\BatchCostController;
use App\Http\Controllers\OperationalController;
use App\Http\Controllers\OwnerController;
use App\Http\Controllers\PropertyInventoryController;
use App\Http\Controllers\QuickCostController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Public auth route
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

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

    // Property Inventory
    Route::get('/properties/{propertyId}/inventory', [PropertyInventoryController::class, 'index']);
    Route::post('/properties/{propertyId}/inventory', [PropertyInventoryController::class, 'store']);
    Route::put('/properties/{propertyId}/inventory/{id}', [PropertyInventoryController::class, 'update']);
    Route::delete('/properties/{propertyId}/inventory/{id}', [PropertyInventoryController::class, 'destroy']);

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
        Route::get('/channels', [AnalyticsController::class, 'channels']);
        Route::get('/payment-methods', [AnalyticsController::class, 'paymentMethods']);
    });

    // Owner management
    Route::get('/owners', [OwnerController::class, 'index']);
    Route::post('/owners', [OwnerController::class, 'store']);
    Route::get('/owners/{id}', [OwnerController::class, 'show']);
    Route::put('/owners/{id}', [OwnerController::class, 'update']);

    // Standard costs
    Route::get('/standard-costs', [ReportController::class, 'standardCostsIndex']);
    Route::post('/standard-costs', [ReportController::class, 'standardCostsStore']);

    // P&L Reports
    Route::prefix('reports')->group(function () {
        Route::get('/pnl/booking/{id}', [ReportController::class, 'bookingPnl']);
        Route::get('/pnl/property/{id}', [ReportController::class, 'propertyPnl']);
        Route::get('/pnl/global', [ReportController::class, 'globalPnl']);
        Route::get('/pnl/owner/{id}', [ReportController::class, 'ownerPnl']);
        Route::get('/owners', [ReportController::class, 'ownersList']);
        Route::get('/proration', [ReportController::class, 'proration']);
        Route::get('/deviations', [ReportController::class, 'deviations']);
    });

    // Operational management
    Route::get('/booking-operations/{bookingId}', [OperationalController::class, 'getOperation']);
    Route::post('/booking-operations/{bookingId}', [OperationalController::class, 'createOperation']);
    Route::put('/booking-operations/{bookingId}', [OperationalController::class, 'updateOperation']);

    Route::get('/property-incidents', [OperationalController::class, 'listIncidents']);
    Route::post('/property-incidents', [OperationalController::class, 'createIncident']);
    Route::put('/property-incidents/{id}', [OperationalController::class, 'updateIncident']);

    Route::get('/exchange-rate', [\App\Http\Controllers\ExchangeRateController::class, 'current']);
});
