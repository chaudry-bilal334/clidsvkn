<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityEventController extends Controller
{
    /**
     * List security events (admin audit). Sensitive by nature: the response
     * never includes payloads that contain credentials; free-text payloads are
     * kept to a human-readable summary.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'severity' => ['nullable', 'string', 'in:info,warning,critical'],
            'type' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SecurityEvent::query()->with('user')->latest('created_at');

        if ($severity = $validated['severity'] ?? null) {
            $query->where('severity', $severity);
        }

        if ($type = $validated['type'] ?? null) {
            $query->where('type', 'like', "%{$type}%");
        }

        $events = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'success' => true,
            'data' => $events->through(fn (SecurityEvent $event): array => [
                'id' => $event->id,
                'timestamp' => $event->created_at?->toISOString(),
                'severity' => $event->severity,
                'actor' => $event->user?->name ?? 'system',
                'ip_address' => $event->ip_address,
                'action' => $event->type,
                'details' => $event->user_agent ?? null,
            ])->all(),
            'meta' => [
                'page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
            ],
        ]);
    }
}
