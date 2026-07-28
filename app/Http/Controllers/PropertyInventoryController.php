<?php

namespace App\Http\Controllers;

use App\Models\PropertyInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyInventoryController extends Controller
{
    /**
     * GET /api/properties/:propertyId/inventory
     */
    public function index(int $propertyId): JsonResponse
    {
        $items = PropertyInventory::where('property_id', $propertyId)
            ->orderBy('item_name')
            ->get();

        return response()->json(['data' => $items]);
    }

    /**
     * POST /api/properties/:propertyId/inventory
     */
    public function store(Request $request, int $propertyId): JsonResponse
    {
        $validated = $request->validate([
            'item_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'quantity' => 'required|integer|min:1',
            'condition' => 'required|in:good,fair,poor,replaced',
            'notes' => 'nullable|string',
        ]);

        $validated['property_id'] = $propertyId;

        $item = PropertyInventory::create($validated);

        return response()->json(['data' => $item], 201);
    }

    /**
     * PUT /api/properties/:propertyId/inventory/:id
     */
    public function update(Request $request, int $propertyId, int $id): JsonResponse
    {
        $item = PropertyInventory::where('property_id', $propertyId)->findOrFail($id);

        $validated = $request->validate([
            'item_name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'quantity' => 'sometimes|required|integer|min:1',
            'condition' => 'sometimes|required|in:good,fair,poor,replaced',
            'notes' => 'nullable|string',
        ]);

        $item->update($validated);

        return response()->json(['data' => $item]);
    }

    /**
     * DELETE /api/properties/:propertyId/inventory/:id
     */
    public function destroy(int $propertyId, int $id): JsonResponse
    {
        $item = PropertyInventory::where('property_id', $propertyId)->findOrFail($id);
        $item->delete();

        return response()->json(['message' => 'Artículo eliminado correctamente.']);
    }
}
