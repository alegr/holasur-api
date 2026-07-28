<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    // ─── Helpers ──────────────────────────────────────────────

    private function dateRange(Request $request): array
    {
        return [
            $request->input('from'),
            $request->input('to'),
        ];
    }

    private function daysInPeriod(?string $from, ?string $to): int
    {
        $start = $from ? Carbon::parse($from) : Carbon::now()->startOfYear();
        $end   = $to   ? Carbon::parse($to)   : Carbon::now();

        return max((int) $start->diffInDays($end), 1);
    }

    // ─── 1. Property Profitability ────────────────────────────

    public function propertyProfitability(Request $request, int $id): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);
        $days = $this->daysInPeriod($from, $to);

        // Revenue from bookings
        $revenueQuery = DB::table('bookings')
            ->where('property_id', $id)
            ->where('is_revenue', true);

        if ($from) $revenueQuery->where('check_in', '>=', $from);
        if ($to)   $revenueQuery->where('check_in', '<=', $to);

        $revenueStats = $revenueQuery->selectRaw('
            COALESCE(SUM(total_amount), 0) as revenue,
            COALESCE(SUM(nights), 0)       as nights_sold,
            COUNT(*)                        as bookings_count
        ')->first();

        $revenue       = (float) $revenueStats->revenue;
        $nightsSold    = (int) $revenueStats->nights_sold;
        $bookingsCount = (int) $revenueStats->bookings_count;
        $avgNightly    = $nightsSold > 0 ? round($revenue / $nightsSold, 2) : 0;
        $occupancy     = round($nightsSold / $days * 100, 2);

        // Direct costs (purchases)
        $purchaseQuery = DB::table('purchases')
            ->where('property_id', $id)
            ->whereIn('imputation_type', ['operation', 'property']);

        if ($from) $purchaseQuery->where('receipt_date', '>=', $from);
        if ($to)   $purchaseQuery->where('receipt_date', '<=', $to);

        $directCosts = (float) $purchaseQuery->sum('total');

        // Indirect costs (expenses)
        $expenseQuery = DB::table('expenses')
            ->where('property_id', $id);

        if ($from) $expenseQuery->where('due_date', '>=', $from);
        if ($to)   $expenseQuery->where('due_date', '<=', $to);

        $indirectCosts = (float) $expenseQuery->sum('amount');

        // Costs by economic responsible (purchases + expenses combined)
        $holasurPurchases = DB::table('purchases')
            ->where('property_id', $id)
            ->where('economic_responsible', 'holasur');
        if ($from) $holasurPurchases->where('receipt_date', '>=', $from);
        if ($to)   $holasurPurchases->where('receipt_date', '<=', $to);

        $ownerPurchases = DB::table('purchases')
            ->where('property_id', $id)
            ->where('economic_responsible', 'owner');
        if ($from) $ownerPurchases->where('receipt_date', '>=', $from);
        if ($to)   $ownerPurchases->where('receipt_date', '<=', $to);

        $holasurCosts = (float) $holasurPurchases->sum('total');
        $ownerCosts   = (float) $ownerPurchases->sum('total');

        $grossMargin = $revenue - $directCosts;
        $netMargin   = $revenue - $directCosts - $indirectCosts;
        $roiPercent  = $revenue > 0 ? round($netMargin / $revenue * 100, 2) : 0;

        return response()->json([
            'data' => [
                'property_id'     => $id,
                'period'          => ['from' => $from, 'to' => $to],
                'revenue'         => $revenue,
                'nights_sold'     => $nightsSold,
                'bookings_count'  => $bookingsCount,
                'avg_nightly_rate'=> $avgNightly,
                'occupancy_rate'  => $occupancy,
                'days_in_period'  => $days,
                'direct_costs'    => $directCosts,
                'indirect_costs'  => $indirectCosts,
                'holasur_costs'   => $holasurCosts,
                'owner_costs'     => $ownerCosts,
                'gross_margin'    => $grossMargin,
                'net_margin'      => $netMargin,
                'roi_percent'     => $roiPercent,
            ],
        ]);
    }

    // ─── 2. Properties Ranking ────────────────────────────────

    public function propertiesRanking(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);
        $days = $this->daysInPeriod($from, $to);

        $properties = DB::table('properties')
            ->select('properties.id', 'properties.name')
            ->get();

        $result = $properties->map(function ($prop) use ($from, $to, $days) {
            $rq = DB::table('bookings')
                ->where('property_id', $prop->id)
                ->where('is_revenue', true);
            if ($from) $rq->where('check_in', '>=', $from);
            if ($to)   $rq->where('check_in', '<=', $to);

            $stats = $rq->selectRaw('
                COALESCE(SUM(total_amount), 0) as revenue,
                COALESCE(SUM(nights), 0)       as nights_sold,
                COUNT(*)                        as bookings_count
            ')->first();

            $pq = DB::table('purchases')
                ->where('property_id', $prop->id);
            if ($from) $pq->where('receipt_date', '>=', $from);
            if ($to)   $pq->where('receipt_date', '<=', $to);
            $purchaseCosts = (float) $pq->sum('total');

            $eq = DB::table('expenses')
                ->where('property_id', $prop->id);
            if ($from) $eq->where('due_date', '>=', $from);
            if ($to)   $eq->where('due_date', '<=', $to);
            $expenseCosts = (float) $eq->sum('amount');

            $revenue = (float) $stats->revenue;
            $costs   = $purchaseCosts + $expenseCosts;

            return [
                'id'             => $prop->id,
                'name'           => $prop->name,
                'revenue'        => $revenue,
                'costs'          => $costs,
                'net_margin'     => $revenue - $costs,
                'bookings_count' => (int) $stats->bookings_count,
                'occupancy_rate' => round((int) $stats->nights_sold / $days * 100, 2),
            ];
        })
        ->sortByDesc('net_margin')
        ->values();

        return response()->json(['data' => $result]);
    }

    // ─── 3. Revenue by Channel ────────────────────────────────

    public function revenueByChannel(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        $query = DB::table('bookings')
            ->where('is_revenue', true);

        if ($from) $query->where('check_in', '>=', $from);
        if ($to)   $query->where('check_in', '<=', $to);

        $channels = (clone $query)
            ->select('channel')
            ->selectRaw('COUNT(*)                        as bookings_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0)  as total_revenue')
            ->selectRaw('COALESCE(AVG(total_amount), 0)  as avg_amount')
            ->groupBy('channel')
            ->orderByDesc('total_revenue')
            ->get();

        $grandTotal = $channels->sum('total_revenue');

        $channels = $channels->map(function ($ch) use ($grandTotal) {
            $ch->total_revenue = (float) $ch->total_revenue;
            $ch->avg_amount    = round((float) $ch->avg_amount, 2);
            $ch->percentage_of_total = $grandTotal > 0
                ? round($ch->total_revenue / $grandTotal * 100, 2)
                : 0;
            return $ch;
        });

        return response()->json(['data' => $channels]);
    }

    // ─── 4. Revenue by Month ──────────────────────────────────

    public function revenueByMonth(Request $request): JsonResponse
    {
        $year = $request->input('year', date('Y'));

        $revenueByMonth = DB::table('bookings')
            ->where('is_revenue', true)
            ->whereRaw('EXTRACT(YEAR FROM check_in) = ?', [$year])
            ->selectRaw("TO_CHAR(check_in, 'YYYY-MM') as month")
            ->selectRaw('COUNT(*)                       as bookings_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue')
            ->groupByRaw("TO_CHAR(check_in, 'YYYY-MM')")
            ->orderBy('month')
            ->get();

        // Costs per month (purchases by receipt_date, expenses by due_date)
        $purchasesByMonth = DB::table('purchases')
            ->whereRaw('EXTRACT(YEAR FROM receipt_date) = ?', [$year])
            ->selectRaw("TO_CHAR(receipt_date, 'YYYY-MM') as month")
            ->selectRaw('COALESCE(SUM(total), 0)          as costs')
            ->groupByRaw("TO_CHAR(receipt_date, 'YYYY-MM')")
            ->pluck('costs', 'month');

        $expensesByMonth = DB::table('expenses')
            ->whereRaw('EXTRACT(YEAR FROM due_date) = ?', [$year])
            ->selectRaw("TO_CHAR(due_date, 'YYYY-MM') as month")
            ->selectRaw('COALESCE(SUM(amount), 0)     as costs')
            ->groupByRaw("TO_CHAR(due_date, 'YYYY-MM')")
            ->pluck('costs', 'month');

        $data = $revenueByMonth->map(function ($row) use ($purchasesByMonth, $expensesByMonth) {
            $revenue = (float) $row->revenue;
            $costs   = (float) ($purchasesByMonth[$row->month] ?? 0)
                     + (float) ($expensesByMonth[$row->month] ?? 0);
            return [
                'month'          => $row->month,
                'bookings_count' => (int) $row->bookings_count,
                'revenue'        => $revenue,
                'costs'          => $costs,
                'net_margin'     => $revenue - $costs,
            ];
        });

        return response()->json(['data' => $data]);
    }

    // ─── 5. Revenue by Property ───────────────────────────────

    public function revenueByProperty(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        $query = DB::table('bookings')
            ->join('properties', 'properties.id', '=', 'bookings.property_id')
            ->where('bookings.is_revenue', true);

        if ($from) $query->where('bookings.check_in', '>=', $from);
        if ($to)   $query->where('bookings.check_in', '<=', $to);

        $data = $query
            ->select('properties.id', 'properties.name')
            ->selectRaw('COUNT(*)                                   as bookings_count')
            ->selectRaw('COALESCE(SUM(bookings.total_amount), 0)    as revenue')
            ->selectRaw('COALESCE(AVG(bookings.total_amount), 0)    as avg_per_booking')
            ->groupBy('properties.id', 'properties.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(function ($row) {
                $row->revenue         = (float) $row->revenue;
                $row->avg_per_booking = round((float) $row->avg_per_booking, 2);
                return $row;
            });

        return response()->json(['data' => $data]);
    }

    // ─── 6. Costs Summary ─────────────────────────────────────

    public function costsSummary(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        // Total purchases
        $pq = DB::table('purchases');
        if ($from) $pq->where('receipt_date', '>=', $from);
        if ($to)   $pq->where('receipt_date', '<=', $to);
        $totalPurchases = (float) $pq->sum('total');

        // Total expenses
        $eq = DB::table('expenses');
        if ($from) $eq->where('due_date', '>=', $from);
        if ($to)   $eq->where('due_date', '<=', $to);
        $totalExpenses = (float) $eq->sum('amount');

        // By category (purchase_items joined to cost_categories)
        $byCatQuery = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->leftJoin('cost_categories', 'cost_categories.id', '=', 'purchase_items.cost_category_id');
        if ($from) $byCatQuery->where('purchases.receipt_date', '>=', $from);
        if ($to)   $byCatQuery->where('purchases.receipt_date', '<=', $to);

        $byCategory = $byCatQuery
            ->selectRaw("COALESCE(cost_categories.name, 'Uncategorized') as category")
            ->selectRaw('COALESCE(SUM(purchase_items.total), 0)          as total')
            ->groupByRaw("COALESCE(cost_categories.name, 'Uncategorized')")
            ->orderByDesc('total')
            ->get();

        // By imputation type (purchases)
        $byImpQuery = DB::table('purchases');
        if ($from) $byImpQuery->where('receipt_date', '>=', $from);
        if ($to)   $byImpQuery->where('receipt_date', '<=', $to);

        $byImputation = $byImpQuery
            ->select('imputation_type')
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->groupBy('imputation_type')
            ->orderByDesc('total')
            ->get();

        // By economic responsible (purchases)
        $byRespQuery = DB::table('purchases');
        if ($from) $byRespQuery->where('receipt_date', '>=', $from);
        if ($to)   $byRespQuery->where('receipt_date', '<=', $to);

        $byResponsible = $byRespQuery
            ->select('economic_responsible')
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->groupBy('economic_responsible')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'data' => [
                'total_purchases' => $totalPurchases,
                'total_expenses'  => $totalExpenses,
                'total_costs'     => $totalPurchases + $totalExpenses,
                'by_category'     => $byCategory,
                'by_imputation'   => $byImputation,
                'by_responsible'  => $byResponsible,
            ],
        ]);
    }

    // ─── 7. Cash Flow (enhanced – HS-44) ─────────────────────

    public function cashflow(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);
        $months = (int) $request->input('months', 6);
        $today  = Carbon::today();

        // Determine the range: past months from 'from' (or start of year) to 'months' into the future
        $periodStart = $from ? Carbon::parse($from)->startOfMonth() : $today->copy()->startOfYear();
        $periodEnd   = $today->copy()->addMonths($months)->endOfMonth();

        // Build a list of all months in range
        $allMonths = [];
        $cursor = $periodStart->copy();
        while ($cursor->lte($periodEnd)) {
            $allMonths[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        // Real income received (avantio_payments type=received)
        $incomeReceived = DB::table('avantio_payments')
            ->where('payment_type', 'received')
            ->where('date', '>=', $periodStart->toDateString())
            ->where('date', '<=', $periodEnd->toDateString())
            ->selectRaw("TO_CHAR(date, 'YYYY-MM') as month")
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->groupByRaw("TO_CHAR(date, 'YYYY-MM')")
            ->pluck('amount', 'month');

        // Real payments made (avantio_payments type=made)
        $paymentsMade = DB::table('avantio_payments')
            ->where('payment_type', 'made')
            ->where('date', '>=', $periodStart->toDateString())
            ->where('date', '<=', $periodEnd->toDateString())
            ->selectRaw("TO_CHAR(date, 'YYYY-MM') as month")
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->groupByRaw("TO_CHAR(date, 'YYYY-MM')")
            ->pluck('amount', 'month');

        // Purchase costs by month
        $purchasesByMonth = DB::table('purchases')
            ->where('receipt_date', '>=', $periodStart->toDateString())
            ->where('receipt_date', '<=', $periodEnd->toDateString())
            ->selectRaw("TO_CHAR(receipt_date, 'YYYY-MM') as month")
            ->selectRaw('COALESCE(SUM(total), 0) as amount')
            ->groupByRaw("TO_CHAR(receipt_date, 'YYYY-MM')")
            ->pluck('amount', 'month');

        // Expense costs by month
        $expensesByMonth = DB::table('expenses')
            ->where('due_date', '>=', $periodStart->toDateString())
            ->where('due_date', '<=', $periodEnd->toDateString())
            ->selectRaw("TO_CHAR(due_date, 'YYYY-MM') as month")
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->groupByRaw("TO_CHAR(due_date, 'YYYY-MM')")
            ->pluck('amount', 'month');

        // Future projected income: bookings with check_in in the future
        $futureBookings = DB::table('bookings')
            ->where('is_revenue', true)
            ->where('check_in', '>', $today->toDateString())
            ->where('check_in', '<=', $periodEnd->toDateString())
            ->selectRaw("TO_CHAR(check_in, 'YYYY-MM') as month")
            ->selectRaw('COALESCE(SUM(total_amount), 0) as amount')
            ->groupByRaw("TO_CHAR(check_in, 'YYYY-MM')")
            ->pluck('amount', 'month');

        $todayMonth = $today->format('Y-m');
        $runningBalance = 0.0;
        $monthlyData = [];

        foreach ($allMonths as $m) {
            $isFuture = $m > $todayMonth;

            $income = (float) ($incomeReceived[$m] ?? 0);
            $paid   = (float) ($paymentsMade[$m] ?? 0);
            $costs  = (float) ($purchasesByMonth[$m] ?? 0)
                    + (float) ($expensesByMonth[$m] ?? 0);
            $projected = (float) ($futureBookings[$m] ?? 0);

            // For future months, add projected booking income
            if ($isFuture) {
                $income += $projected;
            }

            $totalOut = $paid + $costs;
            $netFlow  = $income - $totalOut;
            $runningBalance += $netFlow;

            $monthlyData[] = [
                'month'            => $m,
                'is_future'        => $isFuture,
                'income_received'  => round($income, 2),
                'payments_made'    => round($totalOut, 2),
                'net_flow'         => round($netFlow, 2),
                'running_balance'  => round($runningBalance, 2),
                'projected_income' => $isFuture ? round($projected, 2) : 0,
            ];
        }

        return response()->json([
            'data' => [
                'months'  => $months,
                'monthly' => $monthlyData,
            ],
        ]);
    }

    // ─── 8. Global KPIs (enhanced – HS-45) ───────────────────

    public function kpis(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        // Revenue
        $rq = DB::table('bookings')->where('is_revenue', true);
        if ($from) $rq->where('check_in', '>=', $from);
        if ($to)   $rq->where('check_in', '<=', $to);

        $revenueStats = $rq->selectRaw('
            COALESCE(SUM(total_amount), 0) as total_revenue,
            COALESCE(SUM(nights), 0)       as total_nights,
            COUNT(*)                        as total_bookings
        ')->first();

        $totalRevenue  = (float) $revenueStats->total_revenue;
        $totalNights   = (int) $revenueStats->total_nights;
        $totalBookings = (int) $revenueStats->total_bookings;

        // Commission from raw_data
        $commissionQuery = DB::table('bookings')
            ->where('is_revenue', true);
        if ($from) $commissionQuery->where('check_in', '>=', $from);
        if ($to)   $commissionQuery->where('check_in', '<=', $to);
        $allBookings = $commissionQuery->select('id', 'total_amount', 'raw_data', 'channel')->get();

        $totalCommission = 0.0;
        foreach ($allBookings as $b) {
            $rawData = json_decode($b->raw_data, true) ?? [];
            $csv = $rawData['_csv'] ?? [];
            $commVal = $csv['Portal/Intermediary Commission: calculated commission'] ?? null;
            if ($commVal !== null && $commVal !== '') {
                $cleaned = preg_replace('/[^\d.,-]/', '', $commVal);
                $cleaned = str_replace(',', '.', $cleaned);
                $totalCommission += (float) $cleaned;
            }
        }

        // Costs
        $pq = DB::table('purchases');
        if ($from) $pq->where('receipt_date', '>=', $from);
        if ($to)   $pq->where('receipt_date', '<=', $to);
        $totalPurchases = (float) $pq->sum('total');

        $eq = DB::table('expenses');
        if ($from) $eq->where('due_date', '>=', $from);
        if ($to)   $eq->where('due_date', '<=', $to);
        $totalExpenses = (float) $eq->sum('amount');

        $totalCosts = $totalPurchases + $totalExpenses;

        // Active properties
        $totalProperties = (int) DB::table('properties')
            ->whereRaw("LOWER(status) = 'active'")
            ->count();

        // Avg occupancy
        $days = $this->daysInPeriod($from, $to);
        $availableNights = $totalProperties * $days;
        $avgOccupancy = $availableNights > 0
            ? round($totalNights / $availableNights * 100, 2)
            : 0;

        // Avg stay
        $avgStay = $totalBookings > 0
            ? round($totalNights / $totalBookings, 1)
            : 0;

        // Avg nightly rate
        $avgNightlyRate = $totalNights > 0
            ? round($totalRevenue / $totalNights, 2)
            : 0;

        // Revenue per property
        $revenuePerProperty = $totalProperties > 0
            ? round($totalRevenue / $totalProperties, 2)
            : 0;

        // Gross margin (revenue - commissions)
        $grossMargin = $totalRevenue - $totalCommission;
        $grossMarginPercent = $totalRevenue > 0
            ? round($grossMargin / $totalRevenue * 100, 2)
            : 0;

        // Net margin
        $netMargin = $totalRevenue - $totalCommission - $totalCosts;
        $netMarginPercent = $totalRevenue > 0
            ? round($netMargin / $totalRevenue * 100, 2)
            : 0;

        // Avg commission rate
        $avgCommissionRate = $totalRevenue > 0
            ? round($totalCommission / $totalRevenue * 100, 2)
            : 0;

        // Top channel
        $tcq = DB::table('bookings')
            ->where('is_revenue', true);
        if ($from) $tcq->where('check_in', '>=', $from);
        if ($to)   $tcq->where('check_in', '<=', $to);

        $topChannel = $tcq
            ->select('channel')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as channel_revenue')
            ->groupBy('channel')
            ->orderByDesc('channel_revenue')
            ->first();

        // Top property
        $tpq = DB::table('bookings')
            ->join('properties', 'properties.id', '=', 'bookings.property_id')
            ->where('bookings.is_revenue', true);
        if ($from) $tpq->where('bookings.check_in', '>=', $from);
        if ($to)   $tpq->where('bookings.check_in', '<=', $to);

        $topProperty = $tpq
            ->select('properties.id', 'properties.name')
            ->selectRaw('COALESCE(SUM(bookings.total_amount), 0) as property_revenue')
            ->groupBy('properties.id', 'properties.name')
            ->orderByDesc('property_revenue')
            ->first();

        // Collection efficiency (received payments / total revenue * 100)
        $receivedQuery = DB::table('avantio_payments')
            ->where('payment_type', 'received');
        if ($from) $receivedQuery->where('date', '>=', $from);
        if ($to)   $receivedQuery->where('date', '<=', $to);
        $totalReceived = (float) $receivedQuery->sum('amount');

        $collectionEfficiency = $totalRevenue > 0
            ? round($totalReceived / $totalRevenue * 100, 2)
            : 0;

        // Avg days to collect (avg diff between payment date and booking check_in)
        $avgDaysToCollect = 0;
        $collectJoin = DB::table('avantio_payments')
            ->join('bookings', function ($join) {
                $join->on('bookings.avantio_reference', '=', 'avantio_payments.booking_reference')
                     ->orOn('bookings.avantio_id', '=', 'avantio_payments.booking_reference');
            })
            ->where('avantio_payments.payment_type', 'received')
            ->whereNotNull('bookings.check_in')
            ->whereNotNull('avantio_payments.date');
        if ($from) $collectJoin->where('avantio_payments.date', '>=', $from);
        if ($to)   $collectJoin->where('avantio_payments.date', '<=', $to);

        try {
            $avgDaysResult = $collectJoin->selectRaw(
                'AVG(ABS(EXTRACT(EPOCH FROM (avantio_payments.date::timestamp - bookings.check_in::timestamp)) / 86400)) as avg_days'
            )->first();
            if ($avgDaysResult && $avgDaysResult->avg_days !== null) {
                $avgDaysToCollect = round((float) $avgDaysResult->avg_days, 1);
            }
        } catch (\Exception $e) {
            $avgDaysToCollect = 0;
        }

        return response()->json([
            'data' => [
                'total_revenue'         => $totalRevenue,
                'total_costs'           => $totalCosts,
                'net_margin'            => $netMargin,
                'total_properties'      => $totalProperties,
                'total_bookings'        => $totalBookings,
                'avg_occupancy'         => $avgOccupancy,
                'avg_nightly_rate'      => $avgNightlyRate,
                'top_channel'           => $topChannel?->channel,
                // Enhanced KPIs (HS-45)
                'avg_stay'              => $avgStay,
                'revenue_per_property'  => $revenuePerProperty,
                'gross_margin'          => $grossMargin,
                'gross_margin_percent'  => $grossMarginPercent,
                'net_margin_percent'    => $netMarginPercent,
                'avg_commission_rate'   => $avgCommissionRate,
                'total_commission'      => $totalCommission,
                'top_property'          => $topProperty ? [
                    'id'      => $topProperty->id,
                    'name'    => $topProperty->name,
                    'revenue' => (float) $topProperty->property_revenue,
                ] : null,
                'collection_efficiency' => $collectionEfficiency,
                'avg_days_to_collect'   => $avgDaysToCollect,
            ],
        ]);
    }

    // ─── 9. Enhanced Channel Analysis (HS-42) ────────────────

    public function channels(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        // All revenue bookings with raw_data for commission extraction
        $bookingsQuery = DB::table('bookings')
            ->where('is_revenue', true);
        if ($from) $bookingsQuery->where('check_in', '>=', $from);
        if ($to)   $bookingsQuery->where('check_in', '<=', $to);

        $allBookings = $bookingsQuery
            ->select('id', 'channel', 'total_amount', 'nights', 'property_id', 'raw_data')
            ->get();

        $channelMap = [];

        foreach ($allBookings as $b) {
            $ch = $b->channel ?: 'Desconocido';

            $rawData = json_decode($b->raw_data, true) ?? [];
            $csv = $rawData['_csv'] ?? [];
            $commVal = $csv['Portal/Intermediary Commission: calculated commission'] ?? null;
            $commission = 0.0;
            if ($commVal !== null && $commVal !== '') {
                $cleaned = preg_replace('/[^\d.,-]/', '', $commVal);
                $cleaned = str_replace(',', '.', $cleaned);
                $commission = (float) $cleaned;
            }

            if (!isset($channelMap[$ch])) {
                $channelMap[$ch] = [
                    'channel'          => $ch,
                    'bookings_count'   => 0,
                    'total_revenue'    => 0.0,
                    'total_commission' => 0.0,
                    'total_nights'     => 0,
                    'booking_ids'      => [],
                ];
            }

            $channelMap[$ch]['bookings_count'] += 1;
            $channelMap[$ch]['total_revenue']  += (float) ($b->total_amount ?? 0);
            $channelMap[$ch]['total_commission'] += $commission;
            $channelMap[$ch]['total_nights']   += (int) ($b->nights ?? 0);
            $channelMap[$ch]['booking_ids'][]   = $b->id;
        }

        $grandTotalRevenue = array_sum(array_column($channelMap, 'total_revenue'));

        // Get costs allocated to bookings per channel
        $result = [];
        foreach ($channelMap as $ch => $data) {
            // Direct costs for these bookings
            $channelCosts = 0.0;
            if (!empty($data['booking_ids'])) {
                $channelCosts = (float) DB::table('purchases')
                    ->whereIn('booking_id', $data['booking_ids'])
                    ->sum('total');
            }

            $netRevenue = $data['total_revenue'] - $data['total_commission'] - $channelCosts;

            $result[] = [
                'channel'              => $data['channel'],
                'bookings_count'       => $data['bookings_count'],
                'total_revenue'        => round($data['total_revenue'], 2),
                'total_commission'     => round($data['total_commission'], 2),
                'total_costs'          => round($channelCosts, 2),
                'net_revenue'          => round($netRevenue, 2),
                'avg_booking_value'    => $data['bookings_count'] > 0
                    ? round($data['total_revenue'] / $data['bookings_count'], 2)
                    : 0,
                'avg_nights'           => $data['bookings_count'] > 0
                    ? round($data['total_nights'] / $data['bookings_count'], 1)
                    : 0,
                'market_share_percent' => $grandTotalRevenue > 0
                    ? round($data['total_revenue'] / $grandTotalRevenue * 100, 2)
                    : 0,
            ];
        }

        // Sort by net_revenue desc
        usort($result, fn($a, $b) => $b['net_revenue'] <=> $a['net_revenue']);

        return response()->json(['data' => $result]);
    }

    // ─── 10. Payment Methods Analysis (HS-43) ────────────────

    public function paymentMethods(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        // Group by payment_method and payment_type
        $query = DB::table('avantio_payments');
        if ($from) $query->where('date', '>=', $from);
        if ($to)   $query->where('date', '<=', $to);

        // Total amount for percentage calculation
        $totalAmount = (float) (clone $query)->sum('amount');

        // By payment type
        $byType = [];
        foreach (['received', 'made'] as $type) {
            $typeQuery = (clone $query)->where('payment_type', $type);

            $methods = (clone $typeQuery)
                ->select('payment_method')
                ->selectRaw('COUNT(*) as count')
                ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
                ->selectRaw('COALESCE(AVG(amount), 0) as avg_amount')
                ->groupBy('payment_method')
                ->orderByDesc('total_amount')
                ->get();

            $typeTotal = $methods->sum('total_amount');

            $byType[$type] = $methods->map(function ($row) use ($typeTotal) {
                $total = (float) $row->total_amount;
                return [
                    'payment_method'      => $row->payment_method ?: 'Sin especificar',
                    'count'               => (int) $row->count,
                    'total_amount'        => round($total, 2),
                    'avg_amount'          => round((float) $row->avg_amount, 2),
                    'percentage_of_type'  => $typeTotal > 0
                        ? round($total / $typeTotal * 100, 2)
                        : 0,
                ];
            })->values()->toArray();
        }

        // Overall by payment_method (all types combined)
        $overall = (clone $query)
            ->select('payment_method')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->selectRaw('COALESCE(AVG(amount), 0) as avg_amount')
            ->groupBy('payment_method')
            ->orderByDesc('total_amount')
            ->get()
            ->map(function ($row) use ($totalAmount) {
                $total = (float) $row->total_amount;
                return [
                    'payment_method'       => $row->payment_method ?: 'Sin especificar',
                    'count'                => (int) $row->count,
                    'total_amount'         => round($total, 2),
                    'avg_amount'           => round((float) $row->avg_amount, 2),
                    'percentage_of_total'  => $totalAmount > 0
                        ? round($total / $totalAmount * 100, 2)
                        : 0,
                ];
            })
            ->values()
            ->toArray();

        return response()->json([
            'data' => [
                'total_amount' => round($totalAmount, 2),
                'overall'      => $overall,
                'by_type'      => $byType,
            ],
        ]);
    }
}
