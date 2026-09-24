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
                        'total_properties' => $this->safeCount('properties'),
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
                'details' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function countUsers(): int
    {
        if (!Schema::hasTable('users')) {
            return 0;
        }

        $query = DB::table('users');

        if (Schema::hasColumn('users', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->count();
    }

    private function countPendingProperties(): int
    {
        if (!Schema::hasTable('properties')) {
            return 0;
        }

        foreach (['moderation_status', 'approval_status', 'status'] as $column) {
            if (!Schema::hasColumn('properties', $column)) {
                continue;
            }

            foreach (['pending', 'pending_review', 'under_review'] as $value) {
                $count = DB::table('properties')
                    ->where($column, $value)
                    ->count();

                if ($count > 0) {
                    return $count;
                }
            }
        }

        return 0;
    }

    private function countOpenReports(): int
    {
        $table = $this->reportsTable();

        if (!$table) {
            return 0;
        }

        if (!Schema::hasColumn($table, 'status')) {
            return DB::table($table)->count();
        }

        return DB::table($table)
            ->whereIn('status', ['open', 'pending', 'under_review'])
            ->count();
    }

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

    private function vibeReportSummary(): array
    {
        foreach ([
            'vibe_reports',
            'area_vibe_reports',
            'vibe_report_coverage'
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

    private function pendingProperties(): array
    {
        if (!Schema::hasTable('properties')) {
            return [];
        }

        return DB::table('properties')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function recentReports(): array
    {
        $table = $this->reportsTable();

        if (!$table) {
            return [];
        }

        return DB::table($table)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function reportsTable(): ?string
    {
        foreach ([
            'reports',
            'complaints',
            'complaints_reports',
            'user_reports'
        ] as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return null;
    }

    private function safeCount(string $table): int
    {
        return Schema::hasTable($table)
            ? DB::table($table)->count()
            : 0;
    }
}