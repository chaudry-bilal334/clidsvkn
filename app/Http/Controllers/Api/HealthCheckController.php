<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;

class HealthCheckController extends Controller
{
    public function __construct(private readonly SystemHealthService $health) {}

    public function check(): JsonResponse
    {
        $checks = $this->health->check();

        $allHealthy = collect($checks)->every(fn (array $check) => $check['status'] === 'healthy');
        $hasUnhealthy = collect($checks)->contains(fn (array $check) => $check['status'] === 'unhealthy');

        $overallStatus = $allHealthy ? 'healthy' : ($hasUnhealthy ? 'unhealthy' : 'degraded');

        // Treat 'unavailable' components as degraded, not unhealthy
        $hasUnavailable = collect($checks)->contains(fn (array $check) => $check['status'] === 'unavailable');
        if ($overallStatus === 'healthy' && $hasUnavailable) {
            $overallStatus = 'degraded';
        }

        $httpCode = $overallStatus === 'unhealthy' ? 503 : 200;

        return response()->json([
            'success' => $overallStatus === 'healthy',
            'status' => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $httpCode);
    }
}
