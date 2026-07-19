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

    // ─── 7. Cash Flow ─────────────────────────────────────────

    public function cashflow(Request $request): JsonResponse
    {
        $months = (int) $request->input('months', 6);
        $today  = Carbon::today()->toDateString();
        $horizon = Carbon::today()->addMonths($months)->toDateString();

        // Upcoming income: bookings with check_in in the future
        $upcomingIncome = DB::table('bookings')
            ->where('is_revenue', true)
            ->where('check_in', '>', $today)
            ->where('check_in', '<=', $horizon)
            ->selectRaw("TO_CHAR(check_in, 'YYYY-MM') as month")
            ->selectRaw('COUNT(*)                       as count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as amount')
            ->groupByRaw("TO_CHAR(check_in, 'YYYY-MM')")
            ->orderBy('month')
            ->get();

        // Upcoming expenses: unpaid expenses with due_date in the future
        $upcomingExpenses = DB::table('expenses')
            ->where('due_date', '>', $today)
            ->where('due_date', '<=', $horizon)
            ->where(function ($q) {
                $q->where('payment_status', '!=', 'paid')
                  ->orWhereNull('payment_status');
            })
            ->selectRaw("TO_CHAR(due_date, 'YYYY-MM') as month")
            ->selectRaw('COUNT(*)                       as count')
            ->selectRaw('COALESCE(SUM(amount), 0)       as amount')
            ->groupByRaw("TO_CHAR(due_date, 'YYYY-MM')")
            ->orderBy('month')
            ->get();

        return response()->json([
            'data' => [
                'months'             => $months,
                'upcoming_income'    => $upcomingIncome,
                'upcoming_expenses'  => $upcomingExpenses,
            ],
        ]);
    }

    // ─── 8. Global KPIs ──────────────────────────────────────

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
        $avgOccupancy = $totalProperties > 0
            ? round($totalNights / ($totalProperties * $days) * 100, 2)
            : 0;

        // Avg nightly rate
        $avgNightlyRate = $totalNights > 0
            ? round($totalRevenue / $totalNights, 2)
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

        return response()->json([
            'data' => [
                'total_revenue'    => $totalRevenue,
                'total_costs'      => $totalCosts,
                'net_margin'       => $totalRevenue - $totalCosts,
                'total_properties' => $totalProperties,
                'total_bookings'   => $totalBookings,
                'avg_occupancy'    => $avgOccupancy,
                'avg_nightly_rate' => $avgNightlyRate,
                'top_channel'      => $topChannel?->channel,
            ],
        ]);
    }
}
