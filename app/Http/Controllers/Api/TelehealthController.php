<?php

namespace App\Http\Controllers\Api;

use App\Events\ChatMessageSent;
use App\Events\TelehealthSessionUpdated;
use App\Http\Controllers\Controller;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelehealthController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $role = Str::lower($user->role);

        $query = TelehealthSession::query()
            ->with(['patient:id,name', 'doctor:id,name'])
            ->orderByDesc('created_at');

        if ($role === 'admin') {
            // Admins monitor every session.
        } elseif ($role === 'patient') {
            $query->where('patient_id', $user->patient_id);
        } else {
            $query->where('doctor_id', $user->id);
        }

        $sessions = $query->limit(100)->get();

        return response()->json([
            'success' => true,
            'data' => $sessions->map(fn (TelehealthSession $session) => [
                'id' => $session->id,
                'patient_id' => $session->patient_id,
                'patient_name' => $session->patient?->name,
                'doctor_id' => $session->doctor_id,
                'doctor_name' => $session->doctor?->name,
                'status' => $session->status,
                'created_at' => $session->created_at?->toDateTimeString(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $session = TelehealthSession::create([
            'patient_id' => $validated['patient_id'],
            'doctor_id' => $request->input('doctor_id') ?? $user->id,
            'status' => TelehealthSession::STATUS_ACTIVE,
        ]);

        TelehealthSessionUpdated::dispatch($session, TelehealthSession::STATUS_ACTIVE);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $session->id,
                'patient_id' => $session->patient_id,
                'patient_name' => $session->patient?->name,
                'doctor_id' => $session->doctor_id,
                'doctor_name' => $session->doctor?->name,
                'status' => $session->status,
                'created_at' => $session->created_at?->toDateTimeString(),
            ],
        ], 201);
    }

    public function sendMessage(Request $request, TelehealthSession $session): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $message = $session->messages()->create([
            'sender_id' => $user->id,
            'sender_name' => $user->name,
            'body' => $validated['message'],
        ]);

        ChatMessageSent::dispatch(
            sessionId: $session->id,
            senderId: (int) $user->id,
            senderName: (string) $user->name,
            message: $message->body,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $message->id,
                'session_id' => $session->id,
                'sender_id' => $message->sender_id,
                'sender_name' => $message->sender_name,
                'message' => $message->body,
                'created_at' => $message->created_at?->toDateTimeString(),
            ],
        ], 201);
    }

    public function end(Request $request, TelehealthSession $session): JsonResponse
    {
        if ($session->status !== TelehealthSession::STATUS_ENDED) {
            $session->update(['status' => TelehealthSession::STATUS_ENDED]);
        }

        TelehealthSessionUpdated::dispatch($session, TelehealthSession::STATUS_ENDED, 'The video session has ended.');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $session->id,
                'patient_id' => $session->patient_id,
                'patient_name' => $session->patient?->name,
                'doctor_id' => $session->doctor_id,
                'doctor_name' => $session->doctor?->name,
                'status' => $session->status,
                'created_at' => $session->created_at?->toDateTimeString(),
            ],
        ]);
    }
}
