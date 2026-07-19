<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\ImportLog;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    /**
     * GET /api/properties
     *
     * Optional query params:
     *   ?search=text   — filter by name (case-insensitive)
     *   ?status=Active — filter by status
     */
    public function properties(Request $request): JsonResponse
    {
        $query = Property::query();

        if ($search = $request->input('search')) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search) . '%']);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $properties = $query->orderBy('name')->get()->map(function ($p) {
            $p->is_active = strtolower($p->status ?? '') === 'active';
            return $p;
        });

        return response()->json([
            'data' => $properties,
            'total' => $properties->count(),
        ]);
    }

    /**
     * GET /api/bookings
     *
     * Optional query params:
     *   ?search=text     — filter by reference or property name
     *   ?status=Confirmed — filter by status
     *   ?channel=Airbnb  — filter by channel
     */
    public function bookings(Request $request): JsonResponse
    {
        $query = Booking::with('property:id,name');

        if ($search = $request->input('search')) {
            $lower = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(avantio_reference) LIKE ?', [$lower])
                  ->orWhereRaw('LOWER(avantio_id) LIKE ?', [$lower])
                  ->orWhereHas('property', function ($pq) use ($lower) {
                      $pq->whereRaw('LOWER(name) LIKE ?', [$lower]);
                  });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($channel = $request->input('channel')) {
            $query->where('channel', $channel);
        }

        $bookings = $query->orderByDesc('check_in')->get();

        return response()->json([
            'data' => $bookings,
            'total' => $bookings->count(),
        ]);
    }

    /**
     * GET /api/stats
     *
     * Returns dashboard summary statistics.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'properties_count' => Property::count(),
            'bookings_count' => Booking::count(),
            'last_import' => ImportLog::orderByDesc('started_at')->first(),
        ]);
    }
}
