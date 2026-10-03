<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => [
                        'total_users' => $this->countUsers(),
                        'total_properties' => $this->countProperties(),
                        'pending_properties' => $this->countPendingProperties(),
                        'open_reports' => $this->countOpenReports(),
                    ],

                    'ai_health' => $this->aiHealth(),

                    'vibe_report' => $this->vibeReportSummary(),

                    'pending_properties' => $this->pendingProperties(),

                    'recent_reports' => $this->recentReports(),
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load admin dashboard',
                'details' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    */

    private function countUsers(): int
    {
        if (!Schema::hasTable('users')) {
            return 0;
        }

        return DB::table('users')
            ->whereNull('deleted_at')
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Properties
    |--------------------------------------------------------------------------
    */

    private function countProperties(): int
    {
        if (!Schema::hasTable('properties')) {
            return 0;
        }

        return DB::table('properties')
            ->whereNull('deleted_at')
            ->count();
    }

    private function countPendingProperties(): int
    {
        if (!Schema::hasTable('properties')) {
            return 0;
        }

        return DB::table('properties')
            ->whereNull('deleted_at')
            ->where('moderation_status', 'pending')
            ->count();
    }

    private function pendingProperties(): array
    {
        if (!Schema::hasTable('properties')) {
            return [];
        }

        return DB::table('properties')
            ->whereNull('deleted_at')
            ->where('moderation_status', 'pending')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    private function countOpenReports(): int
    {
        if (!Schema::hasTable('reports')) {
            return 0;
        }

        return DB::table('reports')
            ->whereIn('status', [
                'pending',
                'reviewing',
            ])
            ->count();
    }

    private function recentReports(): array
    {
        if (!Schema::hasTable('reports')) {
            return [];
        }

        return DB::table('reports')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | AI Health
    |--------------------------------------------------------------------------
    */

    private function aiHealth(): array
    {
        if (!Schema::hasTable('api_logs')) {
            return [
                'status' => 'unknown',
                'total_requests' => 0,
                'success_rate' => 0,
                'avg_latency_seconds' => 0,
            ];
        }

        $query = DB::table('api_logs');

        if (Schema::hasColumn('api_logs', 'endpoint')) {
            $query->where(function ($q) {
                $q->where('endpoint', 'like', '%ai%')
                    ->orWhere('endpoint', 'like', '%vibe%');
            });
        }

        $total = (clone $query)->count();

        $success = 0;

        if (Schema::hasColumn('api_logs', 'response_code')) {
            $success = (clone $query)
                ->whereBetween('response_code', [200, 299])
                ->count();
        }

        $avgLatency = 0;

        if (Schema::hasColumn('api_logs', 'execution_time_ms')) {
            $avgLatency = round(
                ((float) ((clone $query)->avg('execution_time_ms') ?? 0)) / 1000,
                3
            );
        }

        $successRate = $total > 0
            ? round(($success / $total) * 100, 2)
            : 0;

        return [
            'status' => $total === 0
                ? 'unknown'
                : ($successRate >= 90 ? 'healthy' : 'degraded'),

            'total_requests' => $total,

            'success_rate' => $successRate,

            'avg_latency_seconds' => $avgLatency,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Vibe Report
    |--------------------------------------------------------------------------
    */

    private function vibeReportSummary(): array
    {
        foreach ([
            'vibe_reports',
            'area_vibe_reports',
            'vibe_report_coverage',
        ] as $table) {

            if (!Schema::hasTable($table)) {
                continue;
            }

            return [
                'available' => true,
                'total' => DB::table($table)->count(),
            ];
        }

        return [
            'available' => false,
            'total' => 0,
        ];
    }
    
}