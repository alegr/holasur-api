<?php

namespace App\Http\Controllers;

use App\Models\CostCategory;
use App\Models\Purchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BatchCostController extends Controller
{
    // ─── Batch Property Costs ────────────────────────────────

    /**
     * GET /api/batch-costs?month=2026-07
     *
     * Returns all property costs for a given month, grouped by property.
     */
    public function batchIndex(Request $request): JsonResponse
    {
        $request->validate([
            'month' => 'required|string|regex:/^\d{4}-\d{2}$/',
        ]);

        $month = $request->input('month');

        $purchases = Purchase::with(['property', 'items.costCategory'])
            ->where('imputation_type', 'property')
            ->where('accounting_month', $month)
            ->where('created_by', 'batch-cost')
            ->whereNotNull('property_id')
            ->get();

        // Group by property_id, flatten items into category -> amount map
        $grouped = $purchases->groupBy('property_id')->map(function ($propertyPurchases, $propertyId) {
            $property = $propertyPurchases->first()->property;
            $costs = [];

            foreach ($propertyPurchases as $purchase) {
                foreach ($purchase->items as $item) {
                    $costs[] = [
                        'category_id' => $item->cost_category_id,
                        'amount' => (float) $item->total,
                        'note' => $purchase->notes,
                    ];
                }
            }

            return [
                'property_id' => (int) $propertyId,
                'property_name' => $property?->name ?? '--',
                'costs' => $costs,
            ];
        });

        return response()->json([
            'data' => array_values($grouped->toArray()),
            'month' => $month,
        ]);
    }

    /**
     * POST /api/batch-costs
     *
     * Creates a Purchase per property with imputation_type="property",
     * accounting_month set to the month, and PurchaseItems for each cost.
     */
    public function batchStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'costs' => 'required|array|min:1',
            'costs.*.property_id' => 'required|exists:properties,id',
            'costs.*.category_id' => 'required|exists:cost_categories,id',
            'costs.*.amount' => 'required|numeric|min:0.01',
            'costs.*.note' => 'nullable|string|max:1000',
        ]);

        $month = $validated['month'];
        $costs = $validated['costs'];

        $created = DB::transaction(function () use ($month, $costs) {
            // Delete existing batch-cost purchases for this month
            $existingIds = Purchase::where('imputation_type', 'property')
                ->where('accounting_month', $month)
                ->where('created_by', 'batch-cost')
                ->pluck('id');

            if ($existingIds->isNotEmpty()) {
                \App\Models\PurchaseItem::whereIn('purchase_id', $existingIds)->delete();
                Purchase::whereIn('id', $existingIds)->delete();
            }

            // Group costs by property_id
            $grouped = collect($costs)->groupBy('property_id');
            $count = 0;

            foreach ($grouped as $propertyId => $propertyCosts) {
                $subtotal = $propertyCosts->sum('amount');
                $subtotal = round($subtotal, 2);

                $purchase = Purchase::create([
                    'purchase_number' => Purchase::generateNumber(),
                    'receipt_date' => now()->toDateString(),
                    'accounting_month' => $month,
                    'economic_responsible' => 'holasur',
                    'imputation_type' => 'property',
                    'property_id' => $propertyId,
                    'subtotal' => $subtotal,
                    'tax' => 0,
                    'total' => $subtotal,
                    'currency' => 'USD',
                    'payment_status' => 'pending',
                    'notes' => null,
                    'created_by' => 'batch-cost',
                ]);

                foreach ($propertyCosts as $cost) {
                    $amount = round((float) $cost['amount'], 2);
                    $category = CostCategory::find($cost['category_id']);

                    $purchase->items()->create([
                        'cost_category_id' => $cost['category_id'],
                        'description' => $category?->name ?? 'Costo mensual',
                        'quantity' => 1,
                        'unit_price' => $amount,
                        'total' => $amount,
                    ]);

                    $count++;
                }
            }

            return $count;
        });

        return response()->json([
            'message' => "Se crearon {$created} registros de costos.",
            'created' => $created,
        ], 201);
    }

    // ─── Structural Costs ────────────────────────────────────

    /**
     * GET /api/structural-costs?month=2026-07
     *
     * Returns structural costs for a given month.
     */
    public function structuralIndex(Request $request): JsonResponse
    {
        $request->validate([
            'month' => 'required|string|regex:/^\d{4}-\d{2}$/',
        ]);

        $month = $request->input('month');

        $purchases = Purchase::with(['items.costCategory'])
            ->where('imputation_type', 'structure')
            ->where('accounting_month', $month)
            ->where('created_by', 'structural-cost')
            ->get();

        $costs = [];
        foreach ($purchases as $purchase) {
            foreach ($purchase->items as $item) {
                $costs[] = [
                    'category_id' => $item->cost_category_id,
                    'category_name' => $item->costCategory?->name ?? '--',
                    'amount' => (float) $item->total,
                    'description' => $item->description,
                ];
            }
        }

        return response()->json([
            'data' => $costs,
            'month' => $month,
        ]);
    }

    /**
     * POST /api/structural-costs
     *
     * Creates Purchases with imputation_type="structure", no property_id.
     */
    public function structuralStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'costs' => 'required|array|min:1',
            'costs.*.category_id' => 'required|exists:cost_categories,id',
            'costs.*.amount' => 'required|numeric|min:0.01',
            'costs.*.description' => 'nullable|string|max:1000',
        ]);

        $month = $validated['month'];
        $costs = $validated['costs'];

        $created = DB::transaction(function () use ($month, $costs) {
            // Delete existing structural-cost purchases for this month
            $existingIds = Purchase::where('imputation_type', 'structure')
                ->where('accounting_month', $month)
                ->where('created_by', 'structural-cost')
                ->pluck('id');

            if ($existingIds->isNotEmpty()) {
                \App\Models\PurchaseItem::whereIn('purchase_id', $existingIds)->delete();
                Purchase::whereIn('id', $existingIds)->delete();
            }

            $subtotal = collect($costs)->sum('amount');
            $subtotal = round($subtotal, 2);

            $purchase = Purchase::create([
                'purchase_number' => Purchase::generateNumber(),
                'receipt_date' => now()->toDateString(),
                'accounting_month' => $month,
                'economic_responsible' => 'holasur',
                'imputation_type' => 'structure',
                'subtotal' => $subtotal,
                'tax' => 0,
                'total' => $subtotal,
                'currency' => 'USD',
                'payment_status' => 'pending',
                'created_by' => 'structural-cost',
            ]);

            $count = 0;
            foreach ($costs as $cost) {
                $amount = round((float) $cost['amount'], 2);
                $description = $cost['description'] ?? '';
                $category = CostCategory::find($cost['category_id']);

                $purchase->items()->create([
                    'cost_category_id' => $cost['category_id'],
                    'description' => $description ?: ($category?->name ?? 'Gasto estructura'),
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'total' => $amount,
                ]);

                $count++;
            }

            return $count;
        });

        return response()->json([
            'message' => "Se crearon {$created} registros de gastos de estructura.",
            'created' => $created,
        ], 201);
    }
}
