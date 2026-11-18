<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\Dashboard\TransactionListResource;
use App\Models\PaymentGateway;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        /** @var array{total_volume: float, total_fees: float, successful_count: int, active_gateways: int} $metrics */
        $metrics = Cache::remember('dashboard_metrics', 300, function (): array {
            $stats = Transaction::where('status', 'PAID')
                ->selectRaw('COALESCE(SUM(total_amount), 0) as total_volume, COALESCE(SUM(fee), 0) as total_fees, COUNT(*) as successful_count')
                ->first();

            /** @var int $activeGateways */
            $activeGateways = Cache::remember(
                'active_gateways_count',
                600,
                static fn (): int => PaymentGateway::where('is_active', true)->count(),
            );

            return [
                'total_volume' => (float) ($stats?->getAttribute('total_volume') ?? 0),
                'total_fees' => (float) ($stats?->getAttribute('total_fees') ?? 0),
                'successful_count' => (int) ($stats?->getAttribute('successful_count') ?? 0),
                'active_gateways' => $activeGateways,
            ];
        });

        return Inertia::render('Dashboard', [
            'metrics' => $metrics,
            'recent_transactions' => Inertia::defer(fn (): array => $this->getRecentTransactions()),
            'volume_trends' => Inertia::defer(fn (): array => $this->getChartData()),
        ]);
    }

    /**
     * Fetch the 10 most recent transactions with eager-loaded relations.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getRecentTransactions(): array
    {
        $transactions = Transaction::with(['paymentMethod.gateway', 'merchant'])
            ->latest()
            ->limit(10)
            ->get();

        return TransactionListResource::collection($transactions)->resolve();
    }

    /**
     * Fetch 30-day transaction volume trends with missing dates zero-filled.
     *
     * @return array<int, array{date: string, volume: float, count: int}>
     */
    private function getChartData(): array
    {
        /** @var array<int, array{date: string, volume: float, count: int}> $chartData */
        $chartData = Cache::remember('dashboard_chart_30d', 300, function (): array {
            $startDate = Carbon::now()->subDays(29)->startOfDay();

            $dailyData = Transaction::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw("SUM(CASE WHEN status = 'PAID' THEN total_amount ELSE 0 END) as volume"),
                DB::raw('COUNT(*) as count')
            )
                ->where('created_at', '>=', $startDate)
                ->groupBy('date')
                ->orderBy('date', 'ASC')
                ->get()
                ->keyBy('date');

            $result = [];
            for ($i = 29; $i >= 0; $i--) {
                $dateString = Carbon::now()->subDays($i)->format('Y-m-d');
                $row = $dailyData->get($dateString);
                $result[] = [
                    'date' => Carbon::parse($dateString)->format('M d'),
                    'volume' => $row !== null ? (float) $row->getAttribute('volume') : 0.0,
                    'count' => $row !== null ? (int) $row->getAttribute('count') : 0,
                ];
            }

            return $result;
        });

        return $chartData;
    }
}
