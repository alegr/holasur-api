<?php

namespace App\Http\Controllers;

use App\Models\CostCategory;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CostController extends Controller
{
    // ─── Cost Categories ──────────────────────────────────────

    public function costCategories(): JsonResponse
    {
        return response()->json([
            'data' => CostCategory::orderBy('type')->orderBy('name')->get(),
        ]);
    }

    // ─── Suppliers ────────────────────────────────────────────

    public function supplierIndex(Request $request): JsonResponse
    {
        $query = Supplier::with('category');

        if ($search = $request->input('search')) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search) . '%']);
        }

        return response()->json([
            'data' => $query->orderBy('name')->get(),
        ]);
    }

    public function supplierStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'tax_id' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:cost_categories,id',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $supplier = Supplier::create($validated);

        return response()->json(['data' => $supplier->load('category')], 201);
    }

    // ─── Purchases ────────────────────────────────────────────

    public function purchaseIndex(Request $request): JsonResponse
    {
        $query = Purchase::with(['supplier', 'property', 'booking', 'owner', 'items']);

        if ($propertyId = $request->input('property_id')) {
            $query->where('property_id', $propertyId);
        }
        if ($bookingId = $request->input('booking_id')) {
            $query->where('booking_id', $bookingId);
        }
        if ($status = $request->input('payment_status')) {
            $query->where('payment_status', $status);
        }
        if ($from = $request->input('date_from')) {
            $query->where('receipt_date', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $query->where('receipt_date', '<=', $to);
        }

        $purchases = $query->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $purchases,
            'total' => $purchases->count(),
        ]);
    }

    public function purchaseShow(int $id): JsonResponse
    {
        $purchase = Purchase::with(['supplier', 'property', 'booking', 'owner', 'items.costCategory'])
            ->findOrFail($id);

        return response()->json(['data' => $purchase]);
    }

    public function purchaseStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'receipt_type' => 'nullable|string|max:255',
            'receipt_number' => 'nullable|string|max:255',
            'receipt_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'paid_date' => 'nullable|date',
            'accounting_month' => 'nullable|string|max:7',
            'economic_responsible' => 'required|in:holasur,owner',
            'owner_id' => 'nullable|exists:owners,id',
            'property_id' => 'nullable|exists:properties,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'imputation_type' => 'required|in:operation,property,owner,structure',
            'tax' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'usd_rate' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:255',
            'payment_status' => 'nullable|in:pending,approved,paid,cancelled',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.cost_category_id' => 'nullable|exists:cost_categories,id',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $purchase = DB::transaction(function () use ($validated) {
            $itemsData = $validated['items'];
            unset($validated['items']);

            // Calculate subtotal from items
            $subtotal = 0;
            $processedItems = [];
            foreach ($itemsData as $item) {
                $qty = $item['quantity'] ?? 1;
                $lineTotal = round($qty * $item['unit_price'], 2);
                $subtotal += $lineTotal;
                $processedItems[] = array_merge($item, [
                    'quantity' => $qty,
                    'total' => $lineTotal,
                ]);
            }

            $tax = $validated['tax'] ?? 0;
            $validated['subtotal'] = $subtotal;
            $validated['total'] = $subtotal + $tax;
            $validated['purchase_number'] = Purchase::generateNumber();

            // Calculate USD total if rate provided
            if (!empty($validated['usd_rate'])) {
                $validated['usd_total'] = round($validated['total'] / $validated['usd_rate'], 2);
            }

            $purchase = Purchase::create($validated);

            foreach ($processedItems as $itemData) {
                $purchase->items()->create($itemData);
            }

            return $purchase;
        });

        return response()->json([
            'data' => $purchase->load(['supplier', 'property', 'booking', 'owner', 'items.costCategory']),
        ], 201);
    }

    public function purchaseUpdate(Request $request, int $id): JsonResponse
    {
        $purchase = Purchase::findOrFail($id);

        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'receipt_type' => 'nullable|string|max:255',
            'receipt_number' => 'nullable|string|max:255',
            'receipt_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'paid_date' => 'nullable|date',
            'accounting_month' => 'nullable|string|max:7',
            'economic_responsible' => 'sometimes|in:holasur,owner',
            'owner_id' => 'nullable|exists:owners,id',
            'property_id' => 'nullable|exists:properties,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'imputation_type' => 'sometimes|in:operation,property,owner,structure',
            'tax' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'usd_rate' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:255',
            'payment_status' => 'nullable|in:pending,approved,paid,cancelled',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'items' => 'sometimes|array|min:1',
            'items.*.cost_category_id' => 'nullable|exists:cost_categories,id',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($purchase, $validated) {
            // If items are provided, replace them
            if (isset($validated['items'])) {
                $itemsData = $validated['items'];
                unset($validated['items']);

                $purchase->items()->delete();

                $subtotal = 0;
                foreach ($itemsData as $item) {
                    $qty = $item['quantity'] ?? 1;
                    $lineTotal = round($qty * $item['unit_price'], 2);
                    $subtotal += $lineTotal;
                    $purchase->items()->create(array_merge($item, [
                        'quantity' => $qty,
                        'total' => $lineTotal,
                    ]));
                }

                $validated['subtotal'] = $subtotal;
                $validated['total'] = $subtotal + ($validated['tax'] ?? $purchase->tax ?? 0);
            }

            if (!empty($validated['usd_rate'])) {
                $total = $validated['total'] ?? $purchase->total;
                $validated['usd_total'] = round((float) $total / $validated['usd_rate'], 2);
            }

            $purchase->update($validated);
        });

        return response()->json([
            'data' => $purchase->fresh(['supplier', 'property', 'booking', 'owner', 'items.costCategory']),
        ]);
    }

    // ─── Expenses ─────────────────────────────────────────────

    public function expenseIndex(Request $request): JsonResponse
    {
        $query = Expense::with(['costCategory', 'property', 'booking', 'owner']);

        if ($propertyId = $request->input('property_id')) {
            $query->where('property_id', $propertyId);
        }
        if ($bookingId = $request->input('booking_id')) {
            $query->where('booking_id', $bookingId);
        }
        if ($ownerId = $request->input('owner_id')) {
            $query->where('owner_id', $ownerId);
        }
        if ($status = $request->input('payment_status')) {
            $query->where('payment_status', $status);
        }
        if ($from = $request->input('date_from')) {
            $query->where('scheduled_date', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $query->where('scheduled_date', '<=', $to);
        }

        $expenses = $query->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $expenses,
            'total' => $expenses->count(),
        ]);
    }

    public function expenseShow(int $id): JsonResponse
    {
        $expense = Expense::with(['costCategory', 'property', 'booking', 'owner'])
            ->findOrFail($id);

        return response()->json(['data' => $expense]);
    }

    public function expenseStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'beneficiary_type' => 'required|in:owner,guest,supplier,employee,government,other',
            'beneficiary_name' => 'required|string|max:255',
            'expense_type' => 'nullable|string|max:255',
            'cost_category_id' => 'nullable|exists:cost_categories,id',
            'property_id' => 'nullable|exists:properties,id',
            'owner_id' => 'nullable|exists:owners,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'imputation_type' => 'required|in:operation,property,owner,structure',
            'amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'usd_rate' => 'nullable|numeric|min:0',
            'is_recurring' => 'boolean',
            'recurrence_frequency' => 'nullable|string|max:255',
            'scheduled_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'paid_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:255',
            'payment_account' => 'nullable|string|max:255',
            'payment_status' => 'nullable|in:scheduled,pending,paid,cancelled',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
        ]);

        $validated['expense_number'] = Expense::generateNumber();

        if (!empty($validated['usd_rate'])) {
            $validated['usd_amount'] = round($validated['amount'] / $validated['usd_rate'], 2);
        }

        $expense = Expense::create($validated);

        return response()->json([
            'data' => $expense->load(['costCategory', 'property', 'booking', 'owner']),
        ], 201);
    }

    public function expenseUpdate(Request $request, int $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        $validated = $request->validate([
            'beneficiary_type' => 'sometimes|in:owner,guest,supplier,employee,government,other',
            'beneficiary_name' => 'sometimes|string|max:255',
            'expense_type' => 'nullable|string|max:255',
            'cost_category_id' => 'nullable|exists:cost_categories,id',
            'property_id' => 'nullable|exists:properties,id',
            'owner_id' => 'nullable|exists:owners,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'imputation_type' => 'sometimes|in:operation,property,owner,structure',
            'amount' => 'sometimes|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'usd_rate' => 'nullable|numeric|min:0',
            'is_recurring' => 'boolean',
            'recurrence_frequency' => 'nullable|string|max:255',
            'scheduled_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'paid_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:255',
            'payment_account' => 'nullable|string|max:255',
            'payment_status' => 'nullable|in:scheduled,pending,paid,cancelled',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
        ]);

        if (!empty($validated['usd_rate'])) {
            $amount = $validated['amount'] ?? $expense->amount;
            $validated['usd_amount'] = round((float) $amount / $validated['usd_rate'], 2);
        }

        $expense->update($validated);

        return response()->json([
            'data' => $expense->fresh(['costCategory', 'property', 'booking', 'owner']),
        ]);
    }
}
