<?php

namespace App\Services;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class VibeAiService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            config('services.vibe_ai.url', 'http://127.0.0.1:8001'),
            '/'
        );
    }

    public function parseSearchQuery(string $rawText, string $language = 'en'): ?array
    {
        $endpoint = 'ai-contextual';
        $url = $this->baseUrl . '/api/search/ai-contextual';

        $startedAt = microtime(true);

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->post($url, [
                    'raw_text' => $rawText,
                    'language' => $language,
                ]);

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            if ($response->successful()) {
                $data = $response->json();

                $this->logApiRequest(
                    endpoint: $endpoint,
                    responseCode: $response->status(),
                    executionTimeMs: $latencyMs,
                    outcome: 'success',
                    model: $this->extractModel($data),
                    fallbackTriggered: $this->extractFallbackTriggered($data)
                );

                return is_array($data) ? $data : null;
            }

            $outcome = $this->detectOutcome(
                $response->status(),
                $response->json()
            );

            $this->logApiRequest(
                endpoint: $endpoint,
                responseCode: $response->status(),
                executionTimeMs: $latencyMs,
                outcome: $outcome,
                model: $this->extractModel($response->json()),
                fallbackTriggered: $this->extractFallbackTriggered($response->json()),
                errorMessage: $response->body()
            );

            Log::error('Vibe AI contextual search failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;

        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $outcome = str_contains(
                strtolower($e->getMessage()),
                'timed out'
            ) ? 'timeout' : 'other_error';

            $this->logApiRequest(
                endpoint: $endpoint,
                responseCode: $outcome === 'timeout' ? 504 : 500,
                executionTimeMs: $latencyMs,
                outcome: $outcome,
                model: null,
                fallbackTriggered: false,
                errorMessage: $e->getMessage()
            );

            Log::error(
                $outcome === 'timeout'
                    ? 'Vibe AI contextual search timeout'
                    : 'Vibe AI contextual search exception',
                ['message' => $e->getMessage()]
            );

            return null;
        }
    }

    
    public function getVibeReport(
        int $propertyId,
        float $latitude,
        float $longitude
    ): ?array {
        $endpoint = 'properties/vibe-report';
        $url = $this->baseUrl . '/api/properties/vibe-report';

        $startedAt = microtime(true);

        $neighborhoodId = DB::table('property_locations')
            ->where('property_id', $propertyId)
            ->value('neighborhood_id');

        $this->setVibeReportPending(
            $propertyId,
            $neighborhoodId
        );

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->post($url, [
                    'property_id' => (string) $propertyId,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]);

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            if ($response->successful()) {
                $data = $response->json();

                if (!is_array($data)) {
                    $data = [];
                }

                $poiCount = $this->extractPoiCount($data);

                DB::table('vibe_reports')->updateOrInsert(
                    ['property_id' => (string) $propertyId,
                    ],
                    [
                        'neighborhood_id' => $neighborhoodId,
                        'status' => 'generated',
                        'poi_count' => $poiCount,
                        'report_data' => json_encode(
                            $data,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                        'generated_at' => now(),
                        'last_refreshed_at' => now(),
                        'error_message' => null,
                        'updated_at' => now(),
                    ]
                );

                $this->logApiRequest(
                    endpoint: $endpoint,
                    responseCode: $response->status(),
                    executionTimeMs: $latencyMs,
                    outcome: 'success',
                    model: $this->extractModel($data),
                    fallbackTriggered: $this->extractFallbackTriggered($data)
                );

                return $data;
            }

            $outcome = $this->detectOutcome(
                $response->status(),
                $response->json()
            );

            $error = $response->body();

            $this->markVibeReportFailed(
                propertyId: $propertyId,
                neighborhoodId: $neighborhoodId,
                errorMessage: $error
            );

            $this->logApiRequest(
                endpoint: $endpoint,
                responseCode: $response->status(),
                executionTimeMs: $latencyMs,
                outcome: $outcome,
                model: $this->extractModel($response->json()),
                fallbackTriggered: $this->extractFallbackTriggered($response->json()),
                errorMessage: $error
            );

            Log::error('Vibe AI report failed', [
               'property_id' => (string) $propertyId,
                'status' => $response->status(),
                'body' => $error,
            ]);

            return null;

        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $outcome = str_contains(
                strtolower($e->getMessage()),
                'timed out'
            ) ? 'timeout' : 'other_error';

            $this->markVibeReportFailed(
                propertyId: $propertyId,
                neighborhoodId: $neighborhoodId,
                errorMessage: $e->getMessage()
            );

            $this->logApiRequest(
                endpoint: $endpoint,
                responseCode: $outcome === 'timeout' ? 504 : 500,
                executionTimeMs: $latencyMs,
                outcome: $outcome,
                model: null,
                fallbackTriggered: false,
                errorMessage: $e->getMessage()
            );

            Log::error(
                $outcome === 'timeout'
                    ? 'Vibe AI report timeout'
                    : 'Vibe AI report exception',
                [
                   'property_id' => (string) $propertyId,
                    'message' => $e->getMessage(),
                ]
            );

            return null;
        }
    }

    private function setVibeReportPending(
        int $propertyId,
        ?int $neighborhoodId
    ): void {
        $existing = DB::table('vibe_reports')
            ->where('property_id', $propertyId)
            ->first();

        if ($existing) {
            DB::table('vibe_reports')
                ->where('property_id', $propertyId)
                ->update([
                    'neighborhood_id' => $neighborhoodId,
                    'status' => 'pending',
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('vibe_reports')->insert([
            'property_id' => (string) $propertyId,
            'neighborhood_id' => $neighborhoodId,
            'status' => 'pending',
            'poi_count' => 0,
            'report_data' => null,
            'generated_at' => null,
            'last_refreshed_at' => null,
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function markVibeReportFailed(
        int $propertyId,
        ?int $neighborhoodId,
        string $errorMessage
    ): void {
        DB::table('vibe_reports')->updateOrInsert(
            [
                'property_id' => (string) $propertyId,
            ],
            [
                'neighborhood_id' => $neighborhoodId,
                'status' => 'failed',
                'error_message' => mb_substr($errorMessage, 0, 5000),
                'last_refreshed_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function extractPoiCount(array $data): int
    {
        $possibleKeys = [
            'pois',
            'poi',
            'points_of_interest',
            'nearby_places',
            'places',
            'amenities_nearby',
        ];

        foreach ($possibleKeys as $key) {
            if (
                array_key_exists($key, $data)
                && is_array($data[$key])
            ) {
                return count($data[$key]);
            }
        }

        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($possibleKeys as $key) {
                if (
                    array_key_exists($key, $data['data'])
                    && is_array($data['data'][$key])
                ) {
                    return count($data['data'][$key]);
                }
            }
        }

        if (isset($data['poi_count']) && is_numeric($data['poi_count'])) {
            return (int) $data['poi_count'];
        }

        if (
            isset($data['data']['poi_count'])
            && is_numeric($data['data']['poi_count'])
        ) {
            return (int) $data['data']['poi_count'];
        }

        return 0;
    }

    private function detectOutcome(
        int $statusCode,
        mixed $body
    ): string {
        if ($statusCode === 429) {
            return 'rate_limited';
        }

        if (in_array($statusCode, [408, 504], true)) {
            return 'timeout';
        }

        if (is_array($body)) {
            if (
                isset($body['choices'])
                && is_array($body['choices'])
                && count($body['choices']) === 0
            ) {
                return 'no_choices';
            }

            if (
                isset($body['data']['choices'])
                && is_array($body['data']['choices'])
                && count($body['data']['choices']) === 0
            ) {
                return 'no_choices';
            }
        }

        return 'other_error';
    }

    private function extractModel(mixed $data): ?string
    {
        if (!is_array($data)) {
            return config('services.vibe_ai.model');
        }

        return $data['model']
            ?? $data['ai_model']
            ?? $data['data']['model']
            ?? config('services.vibe_ai.model');
    }

    private function extractFallbackTriggered(mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        return (bool) (
            $data['fallback_triggered']
            ?? $data['data']['fallback_triggered']
            ?? false
        );
    }

    private function logApiRequest(
        string $endpoint,
        int $responseCode,
        int $executionTimeMs,
        string $outcome,
        ?string $model = null,
        bool $fallbackTriggered = false,
        ?string $errorMessage = null
    ): void {
        try {
            DB::table('api_logs')->insert([
                'user_id' => auth()->id(),
                'endpoint' => $endpoint,
                'method' => 'POST',
                'request_headers' => null,
                'request_body' => null,
                'response_code' => $responseCode,
                'execution_time_ms' => $executionTimeMs,
                'ip_address' => request()?->ip(),
                'outcome' => $outcome,
                'ai_model' => $model,
                'fallback_triggered' => $fallbackTriggered,
                'error_message' => $errorMessage
                    ? mb_substr($errorMessage, 0, 5000)
                    : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Unable to write AI api log', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}