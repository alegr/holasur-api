<?php

namespace App\Http\Controllers;

use App\Models\CostCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuickCostController extends Controller
{
    /**
     * POST /api/quick-cost
     *
     * Simplified cost entry — creates a Purchase + single PurchaseItem in one call.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:cost_categories,id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:1000',
            'property_id' => 'nullable|exists:properties,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'currency' => 'nullable|string|size:3',
        ]);

        // At least one context must be provided
        if (empty($validated['property_id']) && empty($validated['booking_id'])) {
            return response()->json([
                'message' => 'Se requiere property_id o booking_id.',
                'errors' => ['context' => ['Debe indicar una propiedad o una reserva.']],
            ], 422);
        }

        $category = CostCategory::findOrFail($validated['category_id']);

        $purchase = DB::transaction(function () use ($validated, $category) {
            $amount = round((float) $validated['amount'], 2);
            $currency = $validated['currency'] ?? 'USD';
            $imputationType = !empty($validated['booking_id']) ? 'operation' : 'property';

            $purchase = Purchase::create([
                'purchase_number' => Purchase::generateNumber(),
                'receipt_date' => now()->toDateString(),
                'accounting_month' => now()->format('Y-m'),
                'economic_responsible' => 'holasur',
                'imputation_type' => $imputationType,
                'property_id' => $validated['property_id'] ?? null,
                'booking_id' => $validated['booking_id'] ?? null,
                'subtotal' => $amount,
                'tax' => 0,
                'total' => $amount,
                'currency' => $currency,
                'payment_status' => 'pending',
                'notes' => $validated['note'] ?? null,
                'created_by' => 'quick-cost',
            ]);

            $purchase->items()->create([
                'cost_category_id' => $category->id,
                'description' => $category->name,
                'quantity' => 1,
                'unit_price' => $amount,
                'total' => $amount,
            ]);

            return $purchase;
        });

        return response()->json([
            'data' => $purchase->load(['property', 'booking', 'items.costCategory']),
        ], 201);
    }

    /**
     * GET /api/quick-cost/recent?property_id=X&booking_id=Y
     *
     * Returns the 20 most recent costs for a property or booking.
     */
    public function recent(Request $request): JsonResponse
    {
        $request->validate([
            'property_id' => 'nullable|integer',
            'booking_id' => 'nullable|integer',
        ]);

        $query = Purchase::with(['items.costCategory'])
            ->orderByDesc('created_at')
            ->limit(20);

        if ($bookingId = $request->input('booking_id')) {
            $query->where('booking_id', $bookingId);
        } elseif ($propertyId = $request->input('property_id')) {
            $query->where('property_id', $propertyId);
        } else {
            return response()->json(['data' => []]);
        }

        $purchases = $query->get();

        // Flatten to a simpler list: one row per purchase with category from first item
        $data = $purchases->map(function (Purchase $p) {
            $firstItem = $p->items->first();
            return [
                'id' => $p->id,
                'date' => $p->receipt_date?->format('Y-m-d'),
                'category' => $firstItem?->costCategory?->name ?? '--',
                'amount' => $p->total,
                'currency' => $p->currency,
                'note' => $p->notes,
                'purchase_number' => $p->purchase_number,
            ];
        });

        return response()->json(['data' => $data]);
    }
}
