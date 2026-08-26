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

// Routes (auth deferred)
// Auth
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/user', [AuthController::class, 'user']);

// Import endpoints
Route::post('/import/{entity}', [ImportController::class, 'import'])
    ->whereIn('entity', ['owners', 'properties', 'customers', 'bookings', 'tasks', 'avantio_payments', 'invoices']);

Route::get('/import/logs', [ImportController::class, 'logs']);

// Data endpoints
Route::get('/properties', [ApiController::class, 'properties']);
Route::get('/properties/{id}', [ApiController::class, 'property']);
Route::get('/properties/by-avantio-id/{avantioId}', function ($avantioId) {
    $property = \App\Models\Property::where('avantio_id', $avantioId)->first();
    if (!$property) return response()->json(['error' => 'Not found'], 404);
    return response()->json($property);
});
Route::get('/properties/{id}/services', fn ($id) => \App\Models\PropertyService::where('property_id', $id)->orderBy('name')->get());
Route::post('/properties/{id}/services', function (\Illuminate\Http\Request $request, $id) {
    $data = $request->all();
    $data['property_id'] = $id;
    $service = \App\Models\PropertyService::updateOrCreate(
        ['property_id' => $id, 'name' => $data['name']],
        collect($data)->only(['service_catalog_id', 'is_applicable', 'is_personalized', 'owner_return_percent', 'is_from_avantio'])->toArray()
    );
    return response()->json($service);
});
Route::put('/properties/{id}', function (\Illuminate\Http\Request $request, $id) {
    $property = \App\Models\Property::findOrFail($id);
    $property->update($request->only(['hs_commission_percent', 'contract_data']));
    return response()->json($property);
});
Route::get('/bookings', [ApiController::class, 'bookings']);
Route::get('/bookings/{id}', [ApiController::class, 'booking']);
Route::get('/bookings/{id}/services', fn ($id) => \App\Models\BookingService::where('booking_id', $id)->get());
Route::post('/bookings/{id}/services', function (\Illuminate\Http\Request $request, $id) {
    $booking = \App\Models\Booking::findOrFail($id);
    $data = $request->input('data', []);
    $replace = $request->input('replace', true); // default: replace all
    if ($replace) $booking->services()->delete();
    $services = [];
    foreach ($data as $item) {
        // Link to catalog if concept matches
        if (!isset($item['service_catalog_id']) && isset($item['concept'])) {
            $catalog = \App\Models\ServiceCatalog::where('name', $item['concept'])->first();
            if ($catalog) {
                $item['service_catalog_id'] = $catalog->id;
                // Auto-populate cost if not overridden
                if (empty($item['cost_override']) && $catalog->default_unit_cost) {
                    $item['unit_cost'] = $catalog->default_unit_cost;
                    $total = (float) ($item['total'] ?? 0);
                    $unitPrice = $catalog->default_unit_price ?: (float) preg_replace('/[^0-9.]/', '', $item['price_label'] ?? '0');
                    $item['cost_units'] = $unitPrice > 0 ? round($total / $unitPrice, 2) : 1;
                    $item['total_cost'] = round($item['unit_cost'] * $item['cost_units'], 2);
                }
            }
        }
        $services[] = $booking->services()->create($item);
    }
    return response()->json(['status' => 'ok', 'count' => count($services)]);
});
Route::put('/bookings/{bookingId}/services/{id}', function (\Illuminate\Http\Request $request, $bookingId, $id) {
    $service = \App\Models\BookingService::where('booking_id', $bookingId)->findOrFail($id);
    $service->update($request->only(['concept', 'unit_cost', 'cost_units', 'total_cost', 'cost_override', 'service_catalog_id', 'price_label', 'quantity', 'tax', 'total', 'charge_moment']));
    return response()->json($service);
});
Route::get('/stats', [ApiController::class, 'stats']);

// Service Catalog
Route::get('/service-catalog', fn () => \App\Models\ServiceCatalog::orderBy('name')->get());
Route::post('/service-catalog', function (\Illuminate\Http\Request $request) {
    $item = \App\Models\ServiceCatalog::updateOrCreate(
        ['name' => $request->input('name')],
        $request->only(['category', 'default_unit_cost', 'cost_unit', 'default_unit_price', 'is_active'])
    );
    return response()->json($item);
});
Route::put('/service-catalog/{id}', function (\Illuminate\Http\Request $request, $id) {
    $item = \App\Models\ServiceCatalog::findOrFail($id);
    $item->update($request->only(['name', 'category', 'default_unit_cost', 'cost_unit', 'default_unit_price', 'is_active']));
    return response()->json($item);
});
Route::delete('/service-catalog/{id}', function ($id) {
    $item = \App\Models\ServiceCatalog::findOrFail($id);
    if ($item->is_from_avantio) {
        return response()->json(['error' => 'No se pueden eliminar servicios importados de Avantio'], 403);
    }
    $item->delete();
    return response()->json(['status' => 'ok']);
});
Route::post('/service-catalog/import-avantio', function (\Illuminate\Http\Request $request) {
    $services = $request->input('data', []);
    $created = 0;
    $updated = 0;
    foreach ($services as $s) {
        $name = mb_substr(trim($s['name'] ?? ''), 0, 200);
        if (!$name || strlen($name) < 2) continue;
        try {
            $item = \App\Models\ServiceCatalog::firstOrNew(['name' => $name]);
            $item->is_from_avantio = true;
            $item->category = $s['category'] ?? 'service';
            if (!$item->exists) $created++;
            else $updated++;
            $item->save();
        } catch (\Throwable $e) {
            // Skip invalid entries
            continue;
        }
    }
    return response()->json(['status' => 'ok', 'created' => $created, 'updated' => $updated]);
});
Route::post('/service-catalog/seed-from-bookings', function () {
    // Create catalog entries from existing booking_services concepts
    $concepts = \App\Models\BookingService::select('concept')->distinct()->pluck('concept');
    $created = 0;
    foreach ($concepts as $name) {
        if (!$name) continue;
        \App\Models\ServiceCatalog::firstOrCreate(['name' => $name], ['category' => 'service']);
        $created++;
    }
    return response()->json(['status' => 'ok', 'created' => $created]);
});

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
Route::put('/quick-cost/{id}', [QuickCostController::class, 'update']);
Route::delete('/quick-cost/{id}', [QuickCostController::class, 'destroy']);

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

// Invoices
Route::get('/invoices', fn () => \App\Models\Invoice::orderBy('date', 'desc')->paginate(50));
Route::get('/invoices/{id}', fn ($id) => \App\Models\Invoice::findOrFail($id));

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
