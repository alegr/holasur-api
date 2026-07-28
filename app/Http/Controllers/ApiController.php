<?php

namespace App\Http\Controllers;

use App\Models\AvantioPayment;
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
     * GET /api/properties/{id}
     */
    public function property(int $id): JsonResponse
    {
        $property = Property::with(['owner', 'bookings' => function ($q) {
            $q->orderByDesc('check_in')->limit(50);
        }])->findOrFail($id);

        $property->is_active = strtolower($property->status ?? '') === 'active';

        return response()->json($property);
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
     * GET /api/bookings/{id}
     */
    public function booking(int $id): JsonResponse
    {
        $booking = Booking::with(['property', 'customer'])->findOrFail($id);

        return response()->json($booking);
    }

    /**
     * GET /api/avantio-payments
     *
     * Optional query params:
     *   ?payment_type=received — filter by type (received, made, pending)
     *   ?property_code=X      — filter by property code
     *   ?search=text           — filter by description or counterpart
     *   ?from=2026-01-01       — filter by date range start
     *   ?to=2026-12-31         — filter by date range end
     */
    public function avantioPayments(Request $request): JsonResponse
    {
        $query = AvantioPayment::with('property:id,name');

        if ($type = $request->input('payment_type')) {
            $query->where('payment_type', $type);
        }

        if ($code = $request->input('property_code')) {
            $query->where('property_code', $code);
        }

        if ($search = $request->input('search')) {
            $lower = '%' . mb_strtolower($search) . '%';
            $query->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(description) LIKE ?', [$lower])
                  ->orWhereRaw('LOWER(counterpart) LIKE ?', [$lower])
                  ->orWhereRaw('LOWER(booking_reference) LIKE ?', [$lower]);
            });
        }

        if ($from = $request->input('from')) {
            $query->where('date', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('date', '<=', $to);
        }

        $payments = $query->orderByDesc('date')->get();

        return response()->json([
            'data' => $payments,
            'total' => $payments->count(),
        ]);
    }

    /**
     * GET /api/avantio-payments/summary
     *
     * Returns totals grouped by payment_type.
     */
    public function avantioPaymentsSummary(Request $request): JsonResponse
    {
        $query = AvantioPayment::query();

        if ($from = $request->input('from')) {
            $query->where('date', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('date', '<=', $to);
        }

        $summary = $query
            ->selectRaw("payment_type, COUNT(*) as count, SUM(amount) as total_amount")
            ->groupBy('payment_type')
            ->get()
            ->keyBy('payment_type');

        return response()->json([
            'data' => $summary,
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
