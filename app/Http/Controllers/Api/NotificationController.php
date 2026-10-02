<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private const CHANNELS = ['email', 'sms', 'whatsapp'];

    /**
     * List the authenticated user's notification log, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $logs = NotificationLog::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'success' => true,
            'data' => $logs->through(fn (NotificationLog $log): array => [
                'id' => $log->id,
                'message' => $log->title ?: $log->body,
                'channel' => $log->channel,
                'read' => $log->read_at !== null,
                'status' => $log->status,
                'action_url' => null,
                'created_at' => $log->created_at?->toISOString(),
            ])->all(),
            'meta' => [
                'page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * Channel opt-in status derived from the user's notification preferences.
     */
    public function channels(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $lastSent = NotificationLog::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->get()
            ->keyBy('channel');

        $data = array_map(static fn (string $channel): array => [
            'channel' => $channel,
            'enabled' => $user->consentsTo($channel),
            'last_sent' => $lastSent->get($channel)?->created_at?->toISOString(),
        ], self::CHANNELS);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Mark one of the user's notifications as read.
     */
    public function markRead(Request $request, NotificationLog $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ((int) $notification->user_id !== (int) $user->id) {
            abort(403, 'You cannot modify another user\'s notification.');
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['success' => true, 'message' => 'Notification marked as read.']);
    }

    /**
     * Toggle the user's opt-in for a messaging channel.
     */
    public function updateChannel(Request $request, string $channel): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        abort_unless(in_array($channel, self::CHANNELS, true), 422);

        /** @var User $user */
        $user = $request->user();

        $preferences = $user->notification_preferences ?? [];
        $preferences[$channel] = (bool) $validated['enabled'];
        $user->update(['notification_preferences' => $preferences]);

        return response()->json([
            'success' => true,
            'data' => ['channel' => $channel, 'enabled' => (bool) $validated['enabled']],
        ]);
    }
}
