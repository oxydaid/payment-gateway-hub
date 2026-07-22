<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        // 1. Core KPIs (PAID transactions only for financial metrics)
        $totalVolume = Transaction::where('status', 'PAID')->sum('total_amount');
        $totalFees = Transaction::where('status', 'PAID')->sum('fee');
        $successfulCount = Transaction::where('status', 'PAID')->count();
        $activeGateways = PaymentGateway::where('is_active', true)->count();

        // 2. Recent Transactions (eager loaded to prevent N+1)
        $recentTransactions = Transaction::with(['paymentMethod.gateway', 'merchant'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'reference_id' => $tx->reference_id,
                    'merchant_name' => $tx->merchant->name,
                    'gateway_name' => $tx->paymentMethod->gateway->name ?? 'Unknown',
                    'method_name' => $tx->paymentMethod->name,
                    'amount' => $tx->amount,
                    'fee' => $tx->fee,
                    'total_amount' => $tx->total_amount,
                    'status' => $tx->status,
                    'created_at' => $tx->created_at->toIso8601String(),
                ];
            });

        // 3. 30-Day Volume Chart Data
        $startDate = Carbon::now()->subDays(29)->startOfDay();

        $dailyData = Transaction::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('SUM(CASE WHEN status = "PAID" THEN total_amount ELSE 0 END) as volume'),
            DB::raw('COUNT(*) as count')
        )
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date', 'ASC')
            ->get()
            ->keyBy('date');

        // Fill in missing dates with zero values for smooth rendering
        $chartData = [];
        for ($i = 29; $i >= 0; $i--) {
            $dateString = Carbon::now()->subDays($i)->format('Y-m-d');
            $chartData[] = [
                'date' => Carbon::parse($dateString)->format('M d'),
                'volume' => $dailyData->has($dateString) ? (float) $dailyData[$dateString]->volume : 0.0,
                'count' => $dailyData->has($dateString) ? (int) $dailyData[$dateString]->count : 0,
            ];
        }

        return Inertia::render('Dashboard', [
            'metrics' => [
                'total_volume' => (float) $totalVolume,
                'total_fees' => (float) $totalFees,
                'successful_count' => $successfulCount,
                'active_gateways' => $activeGateways,
            ],
            'recent_transactions' => $recentTransactions,
            'volume_trends' => $chartData,
            'chart_data' => $chartData,
        ]);
    }
}
