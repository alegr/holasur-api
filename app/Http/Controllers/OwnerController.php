<?php

namespace App\Http\Controllers;

use App\Models\Owner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerController extends Controller
{
    /**
     * GET /api/owners
     */
    public function index(Request $request): JsonResponse
    {
        $query = Owner::query();

        if ($search = $request->input('search')) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search) . '%']);
        }

        $owners = $query->orderBy('name')->get();

        return response()->json([
            'data' => $owners,
            'total' => $owners->count(),
        ]);
    }

    /**
     * GET /api/owners/:id
     */
    public function show(int $id): JsonResponse
    {
        $owner = Owner::with('properties')->findOrFail($id);

        return response()->json(['data' => $owner]);
    }

    /**
     * POST /api/owners
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'avantio_id' => 'nullable|string|max:100',
        ]);

        // Generate avantio_id if not provided
        if (empty($validated['avantio_id'])) {
            $validated['avantio_id'] = 'MANUAL-' . time();
        }

        $owner = Owner::create($validated);

        return response()->json(['data' => $owner], 201);
    }

    /**
     * PUT /api/owners/:id
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $owner = Owner::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
        ]);

        $owner->update($validated);

        return response()->json(['data' => $owner]);
    }
}
