<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdminAiHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            if (!Schema::hasTable('api_logs')) {
                return response()->json([
                    'success' => true,
                    'data' => $this->emptyPayload(),
                ]);
            }

            $base = DB::table('api_logs')
                ->where(function ($q) {
                    $q->where('endpoint', 'like', '%ai%')
                        ->orWhere('endpoint', 'like', '%contextual%')
                        ->orWhere('endpoint', 'like', '%find-properties%')
                        ->orWhere('endpoint', 'like', '%vibe%')
                        ->orWhere('endpoint', 'like', '%reviews/analyze%');
                });

            $totalRequests = (clone $base)->count();

            $successCount = (clone $base)
                ->where('outcome', 'success')
                ->count();

            $successRate = $totalRequests > 0
                ? round(($successCount / $totalRequests) * 100, 2)
                : 0.0;

            $avgMs = (float) ((clone $base)->avg('execution_time_ms') ?? 0);

            return response()->json([
                'success' => true,
                'data' => [
                    'primary_model' => config('services.vibe_ai.model'),
                    'fallback_model' => config('services.vibe_ai.fallback_model'),

                    'total_requests' => $totalRequests,
                    'success_rate' => $successRate,
                    'avg_latency_seconds' => round($avgMs / 1000, 3),

                    'fallback_triggers' => (clone $base)
                        ->where('fallback_triggered', 1)
                        ->count(),

                    'outcome_breakdown' => $this->outcomeBreakdown($base),

                    'endpoints' => $this->endpointStats($base),

                    'recent_events' => (clone $base)
                        ->select([
                            'id',
                            'endpoint',
                            'response_code',
                            'execution_time_ms',
                            'outcome',
                            'ai_model',
                            'fallback_triggered',
                            'created_at',
                        ])
                        ->orderByDesc('id')
                        ->limit(20)
                        ->get()
                        ->map(function ($row) {
                            return [
                                'id' => $row->id,
                                'endpoint' => $row->endpoint,
                                'response_code' => $row->response_code,
                                'latency_seconds' => round(
                                    ((float) $row->execution_time_ms) / 1000,
                                    3
                                ),
                                'outcome' => $row->outcome,
                                'model' => $row->ai_model,
                                'fallback_triggered' => (bool) $row->fallback_triggered,
                                'timestamp' => $row->created_at,
                            ];
                        })
                        ->values(),
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load AI health metrics',
                'details' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function outcomeBreakdown($base): array
    {
        $result = [
            'success' => 0,
            'rate_limited' => 0,
            'timeout' => 0,
            'no_choices' => 0,
            'other_error' => 0,
        ];

        $rows = (clone $base)
            ->select('outcome', DB::raw('COUNT(*) as total'))
            ->groupBy('outcome')
            ->get();

        foreach ($rows as $row) {
            $key = (string) $row->outcome;

            if (array_key_exists($key, $result)) {
                $result[$key] = (int) $row->total;
            } elseif ($key !== '') {
                $result['other_error'] += (int) $row->total;
            }
        }

        return $result;
    }

    private function endpointStats($base): array
    {
        return (clone $base)
            ->select([
                'endpoint',
                DB::raw('COUNT(*) as total_requests'),
                DB::raw(
                    "SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) as successful_requests"
                ),
            ])
            ->groupBy('endpoint')
            ->orderByDesc('total_requests')
            ->get()
            ->map(function ($row) {
                $total = (int) $row->total_requests;
                $successful = (int) $row->successful_requests;

                return [
                    'endpoint' => $row->endpoint,
                    'total_requests' => $total,
                    'success_rate' => $total > 0
                        ? round(($successful / $total) * 100, 2)
                        : 0,
                ];
            })
            ->values()
            ->all();
    }

    private function emptyPayload(): array
    {
        return [
            'primary_model' => config('services.vibe_ai.model'),
            'fallback_model' => config('services.vibe_ai.fallback_model'),
            'total_requests' => 0,
            'success_rate' => 0,
            'avg_latency_seconds' => 0,
            'fallback_triggers' => 0,
            'outcome_breakdown' => [
                'success' => 0,
                'rate_limited' => 0,
                'timeout' => 0,
                'no_choices' => 0,
                'other_error' => 0,
            ],
            'endpoints' => [],
            'recent_events' => [],
        ];
    }
}
