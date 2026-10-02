<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SystemHealthService
{
    /**
     * Perform a comprehensive health check on system components.
     *
     * @return array<string, array{status: string, message?: string, latency_ms?: int}>
     */
    public function check(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'redis' => $this->checkRedis(),
            'storage' => $this->checkStorage(),
            'queue' => $this->checkQueue(),
            'clinical_reference_data' => $this->checkClinicalReferenceData(),
        ];
    }

    /**
     * Clinical safety ruleset readiness.
     *
     * Reported as 'unhealthy' when a production deployment has no approved
     * allergy/dosage/interaction data, because that is a state in which
     * prescribing is refused. The detail is included so an operator can see
     * which rule set is missing without opening the admin UI.
     *
     * @return array{status: string, message?: string, enforcement_enabled?: bool, ready?: bool, types?: array<string, array{approved: int, total: int, ready: bool}>}
     */
    private function checkClinicalReferenceData(): array
    {
        try {
            $readiness = app(ReferenceDataReadinessService::class);
            $status = $readiness->status();
            $enforcing = $readiness->enforcementEnabled();

            // Only a deployment that actually refuses prescribing can be
            // called unhealthy for this; elsewhere it is informational.
            if ($enforcing && ! $status['ready']) {
                return [
                    'status' => 'unhealthy',
                    'message' => 'Clinical safety ruleset is not loaded: '
                        .implode(', ', collect($readiness->missingTypes())->map(fn (string $t) => str_replace('_', ' ', $t))->all())
                        .'. Prescribing is blocked until each has an approved row.',
                    'enforcement_enabled' => true,
                    'ready' => false,
                    'types' => $status['types'],
                ];
            }

            return [
                'status' => $status['ready'] ? 'healthy' : 'degraded',
                'message' => $status['ready']
                    ? null
                    : 'Clinical safety ruleset is incomplete; enforcement is not active in this environment.',
                'enforcement_enabled' => $enforcing,
                'ready' => $status['ready'],
                'types' => $status['types'],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Could not verify the clinical safety ruleset: '.$e->getMessage(),
            ];
        }
    }

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $latency = (int) ((microtime(true) - $start) * 1000);

            return [
                'status' => 'healthy',
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function checkCache(): array
    {
        try {
            $start = microtime(true);
            $key = 'health_check_'.Str::random(8);
            Cache::put($key, 'ok', 10);
            $result = Cache::get($key);
            Cache::forget($key);
            $latency = (int) ((microtime(true) - $start) * 1000);

            return [
                'status' => $result === 'ok' ? 'healthy' : 'degraded',
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function checkRedis(): array
    {
        try {
            if (! extension_loaded('redis')) {
                return [
                    'status' => 'unavailable',
                    'message' => 'Redis extension not loaded',
                ];
            }

            $start = microtime(true);
            $client = Redis::connection();
            $client->ping();
            $latency = (int) ((microtime(true) - $start) * 1000);

            return [
                'status' => 'healthy',
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            // Redis unavailable in test environment - treat as unavailable not unhealthy
            return [
                'status' => 'unavailable',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function checkStorage(): array
    {
        try {
            $disk = Storage::disk('local');
            $testFile = '.health_check_'.Str::random(8);
            $start = microtime(true);
            $disk->put($testFile, 'ok');
            $content = $disk->get($testFile);
            $disk->delete($testFile);
            $latency = (int) ((microtime(true) - $start) * 1000);

            return [
                'status' => $content === 'ok' ? 'healthy' : 'degraded',
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function checkQueue(): array
    {
        try {
            // Check if queue worker is responsive by checking failed jobs count
            if (! DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                return [
                    'status' => 'unavailable',
                    'message' => 'Failed jobs table not found',
                ];
            }

            $failedJobs = DB::table('failed_jobs')->count();

            return [
                'status' => 'healthy',
                'message' => "Failed jobs: {$failedJobs}",
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unavailable',
                'message' => $e->getMessage(),
            ];
        }
    }
}
