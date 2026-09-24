<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class VibeAiService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            config('services.vibe_ai.url', 'http://127.0.0.1:8001'),
            '/'
        );
    }

    /**
     * Send natural-language property search text to the AI service.
     */
    public function parseSearchQuery(
        string $rawText,
        ?string $language = null
    ): ?array {
        $startedAt = microtime(true);
        $endpoint = 'ai-contextual';

        try {
            $payload = [
                'raw_text' => $rawText,
            ];

            if ($language !== null) {
                $payload['language'] = $language;
            }

            $response = Http::timeout(30)
                ->acceptJson()
                ->asJson()
                ->post(
                    "{$this->baseUrl}/api/search/ai-contextual",
                    $payload
                );

            $elapsedMs = $this->elapsedMs($startedAt);
            $json = $response->json();

            $this->recordAiLog(
                endpoint: $endpoint,
                response: $response,
                executionTimeMs: $elapsedMs,
                json: is_array($json) ? $json : null
            );

            if ($response->successful()) {
                return is_array($json) ? $json : [];
            }

            Log::error('Vibe AI contextual search failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (ConnectionException $e) {
            $this->recordAiException(
                endpoint: $endpoint,
                executionTimeMs: $this->elapsedMs($startedAt),
                outcome: 'timeout',
                exception: $e
            );

            Log::error('Vibe AI contextual search timeout', [
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            $this->recordAiException(
                endpoint: $endpoint,
                executionTimeMs: $this->elapsedMs($startedAt),
                outcome: 'other_error',
                exception: $e
            );

            Log::error('Vibe AI service exception', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Request an AI vibe report for a property.
     */
    public function getVibeReport(
        int|string $propertyId,
        float $latitude,
        float $longitude
    ): ?array {
        $startedAt = microtime(true);
        $endpoint = 'properties/vibe-report';

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->asJson()
                ->post(
                    "{$this->baseUrl}/api/properties/vibe-report",
                    [
                        'property_id' => (string) $propertyId,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ]
                );

            $elapsedMs = $this->elapsedMs($startedAt);
            $json = $response->json();

            $this->recordAiLog(
                endpoint: $endpoint,
                response: $response,
                executionTimeMs: $elapsedMs,
                json: is_array($json) ? $json : null
            );

            if ($response->successful()) {
                return is_array($json) ? $json : [];
            }

            Log::error('Vibe AI report failed', [
                'property_id' => (string) $propertyId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (ConnectionException $e) {
            $this->recordAiException(
                endpoint: $endpoint,
                executionTimeMs: $this->elapsedMs($startedAt),
                outcome: 'timeout',
                exception: $e
            );

            Log::error('Vibe AI report timeout', [
                'property_id' => (string) $propertyId,
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            $this->recordAiException(
                endpoint: $endpoint,
                executionTimeMs: $this->elapsedMs($startedAt),
                outcome: 'other_error',
                exception: $e
            );

            Log::error('Vibe AI service exception', [
                'property_id' => (string) $propertyId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function recordAiLog(
        string $endpoint,
        Response $response,
        int $executionTimeMs,
        ?array $json = null
    ): void {
        try {
            $outcome = $this->resolveOutcome($response, $json);

            DB::table('api_logs')->insert([
                'endpoint' => $endpoint,
                'method' => 'POST',
                'response_code' => $response->status(),
                'execution_time_ms' => $executionTimeMs,
                'ip_address' => request()?->ip() ?: '127.0.0.1',

                'outcome' => $outcome,
                'ai_model' => $this->extractModel($json),
                'fallback_triggered' => $this->extractFallbackTriggered($json),
                'error_message' => $response->successful()
                    ? null
                    : mb_substr($response->body(), 0, 2000),

                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to write AI api log', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordAiException(
        string $endpoint,
        int $executionTimeMs,
        string $outcome,
        Throwable $exception
    ): void {
        try {
            DB::table('api_logs')->insert([
                'endpoint' => $endpoint,
                'method' => 'POST',
                'response_code' => $outcome === 'timeout' ? 504 : 500,
                'execution_time_ms' => $executionTimeMs,
                'ip_address' => request()?->ip() ?: '127.0.0.1',

                'outcome' => $outcome,
                'ai_model' => null,
                'fallback_triggered' => 0,
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),

                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to write AI exception log', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveOutcome(
        Response $response,
        ?array $json
    ): string {
        $reportedOutcome = data_get($json, 'outcome');

        if (is_string($reportedOutcome) && $reportedOutcome !== '') {
            return $reportedOutcome;
        }

        if ($response->status() === 429) {
            return 'rate_limited';
        }

        if (in_array($response->status(), [408, 504], true)) {
            return 'timeout';
        }

        if ($response->successful()) {
            $choices = data_get($json, 'choices');

            if (is_array($choices) && count($choices) === 0) {
                return 'no_choices';
            }

            return 'success';
        }

        return 'other_error';
    }

    private function extractModel(?array $json): ?string
    {
        $model = data_get($json, 'model')
            ?? data_get($json, 'meta.model')
            ?? data_get($json, 'metadata.model');

        return is_string($model) && $model !== ''
            ? $model
            : null;
    }

    private function extractFallbackTriggered(?array $json): int
    {
        $value = data_get($json, 'fallback_triggered')
            ?? data_get($json, 'meta.fallback_triggered')
            ?? data_get($json, 'metadata.fallback_triggered')
            ?? false;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
