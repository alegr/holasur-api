<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    // ─── Helpers ──────────────────────────────────────────────

    private function dateRange(Request $request): array
    {
        return [
            $request->input('from'),
            $request->input('to'),
        ];
    }

    /**
     * Parse a numeric value from raw_data->_csv JSON field.
     */
    private function csvFloat(?string $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        // Remove thousands separators and currency symbols, normalise decimal
        $cleaned = preg_replace('/[^\d.,-]/', '', $value);
        $cleaned = str_replace(',', '.', $cleaned);

        return (float) $cleaned;
    }

    // ─── 1. Booking P&L ──────────────────────────────────────

    public function bookingPnl(int $id): JsonResponse
    {
        $booking = DB::table('bookings')
            ->leftJoin('properties', 'properties.id', '=', 'bookings.property_id')
            ->where('bookings.id', $id)
            ->select(
                'bookings.id',
                'bookings.avantio_reference',
                'bookings.check_in',
                'bookings.check_out',
                'bookings.channel',
                'bookings.total_amount',
                'bookings.currency',
                'bookings.raw_data',
                'bookings.property_id',
                'properties.name as property_name'
            )
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        $rawData = json_decode($booking->raw_data, true) ?? [];
        $csv = $rawData['_csv'] ?? [];

        // Revenue
        $rent = $this->csvFloat($csv['Rent with VAT on top'] ?? null);
        $extras = $this->csvFloat($csv['Extras with VAT on top'] ?? null);
        $grossTotal = (float) ($booking->total_amount ?? 0);
        $csvGrossTotal = $this->csvFloat($csv['Booking total with tax'] ?? null);
        if ($csvGrossTotal > 0) {
            $grossTotal = $csvGrossTotal;
        }

        // Costs - platform commission
        $platformCommission = $this->csvFloat($csv['Portal/Intermediary Commission: calculated commission'] ?? null);

        // Costs - direct costs from purchases
        $directCostsQuery = DB::table('purchases')
            ->where('booking_id', $id);

        $directCostsTotal = (float) $directCostsQuery->sum('total');

        // Direct costs breakdown by category
        $directCostsBreakdown = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->leftJoin('cost_categories', 'cost_categories.id', '=', 'purchase_items.cost_category_id')
            ->where('purchases.booking_id', $id)
            ->selectRaw("COALESCE(cost_categories.name, 'Sin categoría') as category")
            ->selectRaw('COALESCE(SUM(purchase_items.total), 0) as amount')
            ->groupByRaw("COALESCE(cost_categories.name, 'Sin categoría')")
            ->orderByDesc('amount')
            ->get()
            ->map(fn($row) => [
                'category' => $row->category,
                'amount' => (float) $row->amount,
            ])
            ->values()
            ->toArray();

        // Payments from CSV
        $paid = $this->csvFloat($csv['Paid'] ?? null);
        $pending = $this->csvFloat($csv['Pending'] ?? null);

        // Payments from avantio_payments
        $receivedPayments = (float) DB::table('avantio_payments')
            ->where('booking_reference', $booking->avantio_reference)
            ->where('payment_type', 'received')
            ->sum('amount');

        // Margins
        $grossMargin = $grossTotal - $platformCommission;
        $netMargin = $grossMargin - $directCostsTotal;
        $marginPercent = $grossTotal > 0 ? round($netMargin / $grossTotal * 100, 2) : 0;

        return response()->json([
            'data' => [
                'booking' => [
                    'id' => $booking->id,
                    'reference' => $booking->avantio_reference,
                    'check_in' => $booking->check_in,
                    'check_out' => $booking->check_out,
                    'property_name' => $booking->property_name,
                    'channel' => $booking->channel,
                ],
                'revenue' => [
                    'rent' => $rent,
                    'extras' => $extras,
                    'gross_total' => $grossTotal,
                ],
                'costs' => [
                    'platform_commission' => $platformCommission,
                    'direct_costs' => $directCostsTotal,
                    'direct_costs_breakdown' => $directCostsBreakdown,
                ],
                'payments' => [
                    'paid' => $paid,
                    'pending' => $pending,
                    'received' => $receivedPayments,
                ],
                'margin' => [
                    'gross_margin' => $grossMargin,
                    'net_margin' => $netMargin,
                    'margin_percent' => $marginPercent,
                ],
            ],
        ]);
    }

    // ─── 2. Property P&L ─────────────────────────────────────

    public function propertyPnl(Request $request, int $id): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        // Property info
        $property = DB::table('properties')
            ->where('id', $id)
            ->select('id', 'name', 'type', 'location')
            ->first();

        if (!$property) {
            return response()->json(['error' => 'Property not found'], 404);
        }

        // Bookings in period
        $bookingsQuery = DB::table('bookings')
            ->where('property_id', $id)
            ->where('is_revenue', true);

        if ($from) $bookingsQuery->where('check_in', '>=', $from);
        if ($to)   $bookingsQuery->where('check_in', '<=', $to);

        $bookingsData = $bookingsQuery
            ->select('id', 'avantio_reference', 'check_in', 'check_out', 'total_amount', 'raw_data', 'channel')
            ->get();

        $totalBookings = $bookingsData->count();
        $grossRevenue = 0.0;
        $totalRent = 0.0;
        $totalExtras = 0.0;
        $platformCommissions = 0.0;
        $bookingsList = [];

        foreach ($bookingsData as $b) {
            $rawData = json_decode($b->raw_data, true) ?? [];
            $csv = $rawData['_csv'] ?? [];

            $rent = $this->csvFloat($csv['Rent with VAT on top'] ?? null);
            $extras = $this->csvFloat($csv['Extras with VAT on top'] ?? null);
            $total = (float) ($b->total_amount ?? 0);
            $csvTotal = $this->csvFloat($csv['Booking total with tax'] ?? null);
            if ($csvTotal > 0) {
                $total = $csvTotal;
            }
            $commission = $this->csvFloat($csv['Portal/Intermediary Commission: calculated commission'] ?? null);

            // Direct costs for this booking
            $bookingCosts = (float) DB::table('purchases')
                ->where('booking_id', $b->id)
                ->sum('total');

            $margin = $total - $commission - $bookingCosts;

            $totalRent += $rent;
            $totalExtras += $extras;
            $grossRevenue += $total;
            $platformCommissions += $commission;

            $bookingsList[] = [
                'id' => $b->id,
                'reference' => $b->avantio_reference,
                'check_in' => $b->check_in,
                'check_out' => $b->check_out,
                'channel' => $b->channel,
                'total' => $total,
                'commission' => $commission,
                'costs' => $bookingCosts,
                'margin' => $margin,
            ];
        }

        // Direct costs (all purchases for this property in period)
        $directCostsQuery = DB::table('purchases')
            ->where('property_id', $id)
            ->whereIn('imputation_type', ['operation', 'booking']);
        if ($from) $directCostsQuery->where('receipt_date', '>=', $from);
        if ($to)   $directCostsQuery->where('receipt_date', '<=', $to);
        $directCosts = (float) $directCostsQuery->sum('total');

        // Costs by category
        $costsByCatQuery = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->leftJoin('cost_categories', 'cost_categories.id', '=', 'purchase_items.cost_category_id')
            ->where('purchases.property_id', $id);
        if ($from) $costsByCatQuery->where('purchases.receipt_date', '>=', $from);
        if ($to)   $costsByCatQuery->where('purchases.receipt_date', '<=', $to);

        $costsByCategory = $costsByCatQuery
            ->selectRaw("COALESCE(cost_categories.name, 'Sin categoría') as category")
            ->selectRaw('COALESCE(SUM(purchase_items.total), 0) as total')
            ->groupByRaw("COALESCE(cost_categories.name, 'Sin categoría')")
            ->orderByDesc('total')
            ->get()
            ->map(fn($row) => [
                'category' => $row->category,
                'total' => (float) $row->total,
            ])
            ->values()
            ->toArray();

        // Monthly costs (property-level purchases)
        $monthlyCostsQuery = DB::table('purchases')
            ->where('property_id', $id)
            ->where('imputation_type', 'property');
        if ($from) $monthlyCostsQuery->where('receipt_date', '>=', $from);
        if ($to)   $monthlyCostsQuery->where('receipt_date', '<=', $to);
        $monthlyCosts = (float) $monthlyCostsQuery->sum('total');

        // Avantio payments
        $propertyCode = DB::table('properties')
            ->where('id', $id)
            ->value('avantio_id');

        $totalReceived = 0.0;
        $totalPending = 0.0;
        $totalPaidOut = 0.0;

        if ($propertyCode) {
            $paymentsBase = DB::table('avantio_payments')
                ->where('property_code', $propertyCode);

            $totalReceived = (float) (clone $paymentsBase)
                ->where('payment_type', 'received')
                ->sum('amount');

            $totalPending = (float) (clone $paymentsBase)
                ->whereIn('payment_type', ['pending', 'outstanding'])
                ->sum('amount');

            $totalPaidOut = (float) (clone $paymentsBase)
                ->where('payment_type', 'made')
                ->sum('amount');
        }

        // Margins
        $grossMargin = $grossRevenue - $platformCommissions;
        $operatingMargin = $grossMargin - $directCosts - $monthlyCosts;
        $marginPercent = $grossRevenue > 0 ? round($operatingMargin / $grossRevenue * 100, 2) : 0;

        return response()->json([
            'data' => [
                'property' => [
                    'id' => $property->id,
                    'name' => $property->name,
                    'type' => $property->type,
                    'location' => $property->location,
                ],
                'period' => ['from' => $from, 'to' => $to],
                'revenue' => [
                    'total_bookings' => $totalBookings,
                    'gross_revenue' => $grossRevenue,
                    'total_rent' => $totalRent,
                    'total_extras' => $totalExtras,
                ],
                'costs' => [
                    'platform_commissions' => $platformCommissions,
                    'direct_costs' => $directCosts,
                    'costs_by_category' => $costsByCategory,
                    'monthly_costs' => $monthlyCosts,
                ],
                'payments' => [
                    'total_received' => $totalReceived,
                    'total_pending' => $totalPending,
                    'total_paid_out' => $totalPaidOut,
                ],
                'margin' => [
                    'gross_margin' => $grossMargin,
                    'operating_margin' => $operatingMargin,
                    'margin_percent' => $marginPercent,
                ],
                'bookings' => $bookingsList,
            ],
        ]);
    }

    // ─── 3. Global P&L ──────────────────────────────────────

    public function globalPnl(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        // All revenue bookings in period
        $bookingsQuery = DB::table('bookings')
            ->where('is_revenue', true);

        if ($from) $bookingsQuery->where('check_in', '>=', $from);
        if ($to)   $bookingsQuery->where('check_in', '<=', $to);

        $allBookings = $bookingsQuery
            ->select('id', 'property_id', 'total_amount', 'channel', 'raw_data')
            ->get();

        $totalBookingsCount = $allBookings->count();
        $grossRevenue = 0.0;
        $platformCommissions = 0.0;

        // Revenue by channel
        $channelMap = [];
        // Revenue by property
        $propertyMap = [];

        foreach ($allBookings as $b) {
            $rawData = json_decode($b->raw_data, true) ?? [];
            $csv = $rawData['_csv'] ?? [];

            $total = (float) ($b->total_amount ?? 0);
            $csvTotal = $this->csvFloat($csv['Booking total with tax'] ?? null);
            if ($csvTotal > 0) {
                $total = $csvTotal;
            }
            $commission = $this->csvFloat($csv['Portal/Intermediary Commission: calculated commission'] ?? null);

            $grossRevenue += $total;
            $platformCommissions += $commission;

            // By channel
            $ch = $b->channel ?: 'Desconocido';
            if (!isset($channelMap[$ch])) {
                $channelMap[$ch] = ['channel' => $ch, 'total' => 0.0, 'count' => 0];
            }
            $channelMap[$ch]['total'] += $total;
            $channelMap[$ch]['count'] += 1;

            // By property
            $pid = $b->property_id;
            if ($pid) {
                if (!isset($propertyMap[$pid])) {
                    $propertyMap[$pid] = [
                        'id' => $pid,
                        'revenue' => 0.0,
                        'commission' => 0.0,
                        'bookings_count' => 0,
                    ];
                }
                $propertyMap[$pid]['revenue'] += $total;
                $propertyMap[$pid]['commission'] += $commission;
                $propertyMap[$pid]['bookings_count'] += 1;
            }
        }

        // Sort channels by total desc
        $byChannel = array_values($channelMap);
        usort($byChannel, fn($a, $b) => $b['total'] <=> $a['total']);

        // Direct costs
        $directCostsQuery = DB::table('purchases')
            ->whereIn('imputation_type', ['operation', 'booking', 'property']);
        if ($from) $directCostsQuery->where('receipt_date', '>=', $from);
        if ($to)   $directCostsQuery->where('receipt_date', '<=', $to);
        $directCosts = (float) $directCostsQuery->sum('total');

        // Structural costs
        $structuralCostsQuery = DB::table('purchases')
            ->where('imputation_type', 'structure');
        if ($from) $structuralCostsQuery->where('receipt_date', '>=', $from);
        if ($to)   $structuralCostsQuery->where('receipt_date', '<=', $to);
        $structuralCosts = (float) $structuralCostsQuery->sum('total');

        // Costs by category
        $costsByCatQuery = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->leftJoin('cost_categories', 'cost_categories.id', '=', 'purchase_items.cost_category_id');
        if ($from) $costsByCatQuery->where('purchases.receipt_date', '>=', $from);
        if ($to)   $costsByCatQuery->where('purchases.receipt_date', '<=', $to);

        $byCategory = $costsByCatQuery
            ->selectRaw("COALESCE(cost_categories.name, 'Sin categoría') as category")
            ->selectRaw('COALESCE(SUM(purchase_items.total), 0) as total')
            ->groupByRaw("COALESCE(cost_categories.name, 'Sin categoría')")
            ->orderByDesc('total')
            ->get()
            ->map(fn($row) => [
                'category' => $row->category,
                'total' => (float) $row->total,
            ])
            ->values()
            ->toArray();

        // Property costs
        $propertyCostsQuery = DB::table('purchases')
            ->select('property_id')
            ->selectRaw('COALESCE(SUM(total), 0) as costs')
            ->whereNotNull('property_id');
        if ($from) $propertyCostsQuery->where('receipt_date', '>=', $from);
        if ($to)   $propertyCostsQuery->where('receipt_date', '<=', $to);
        $propertyCosts = $propertyCostsQuery
            ->groupBy('property_id')
            ->pluck('costs', 'property_id');

        // Property names
        $propertyIds = array_keys($propertyMap);
        $propertyNames = [];
        if (!empty($propertyIds)) {
            $propertyNames = DB::table('properties')
                ->whereIn('id', $propertyIds)
                ->pluck('name', 'id')
                ->toArray();
        }

        // Build properties list
        $propertiesList = [];
        foreach ($propertyMap as $pid => $data) {
            $costs = (float) ($propertyCosts[$pid] ?? 0);
            $revenue = $data['revenue'];
            $margin = $revenue - $data['commission'] - $costs;
            $propertiesList[] = [
                'id' => $pid,
                'name' => $propertyNames[$pid] ?? "Propiedad #{$pid}",
                'revenue' => $revenue,
                'costs' => $costs + $data['commission'],
                'margin' => $margin,
                'bookings_count' => $data['bookings_count'],
            ];
        }
        usort($propertiesList, fn($a, $b) => $b['margin'] <=> $a['margin']);

        // Margins
        $grossMargin = $grossRevenue - $platformCommissions;
        $operatingMargin = $grossMargin - $directCosts;
        $netMargin = $operatingMargin - $structuralCosts;
        $marginPercent = $grossRevenue > 0 ? round($netMargin / $grossRevenue * 100, 2) : 0;

        return response()->json([
            'data' => [
                'period' => ['from' => $from, 'to' => $to],
                'revenue' => [
                    'total_bookings' => $totalBookingsCount,
                    'gross_revenue' => $grossRevenue,
                    'by_channel' => $byChannel,
                ],
                'costs' => [
                    'platform_commissions' => $platformCommissions,
                    'direct_costs' => $directCosts,
                    'structural_costs' => $structuralCosts,
                    'by_category' => $byCategory,
                ],
                'margin' => [
                    'gross_margin' => $grossMargin,
                    'operating_margin' => $operatingMargin,
                    'net_margin' => $netMargin,
                    'margin_percent' => $marginPercent,
                ],
                'properties' => $propertiesList,
            ],
        ]);
    }
}
