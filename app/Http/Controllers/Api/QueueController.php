<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QueueController extends Controller
{
    public function __construct(private readonly QueueService $queue) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(QueueEntry::STATUSES)],
            'doctor_id' => ['nullable', 'integer'],
        ]);

        $query = QueueEntry::query()
            ->with(['patient:id,name,mrn', 'appointment:id,doctor_id,scheduled_at', 'appointment.doctor:id,name'])
            ->orderBy('checked_in_at')
            ->orderBy('id');

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($doctorId = $validated['doctor_id'] ?? null) {
            $query->whereHas('appointment', fn ($query) => $query->where('doctor_id', $doctorId));
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn (QueueEntry $entry) => $this->present($entry))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'appointment_id' => ['required', 'integer', 'exists:appointments,id'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $appointment = Appointment::findOrFail($validated['appointment_id']);

        $entry = $this->queue->checkIn(
            $appointment,
            $user,
            isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
        );

        return response()->json([
            'success' => true,
            'data' => $this->present($entry->load('patient:id,name,mrn', 'appointment:id,doctor_id,scheduled_at', 'appointment.doctor:id,name')),
        ], 201);
    }

    public function update(Request $request, QueueEntry $queueEntry): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(QueueEntry::STATUSES)],
        ]);

        /** @var User $user */
        $user = $request->user();

        $entry = $this->queue->transition($queueEntry, $validated['status'], $user);

        return response()->json([
            'success' => true,
            'data' => $this->present($entry->load('patient:id,name,mrn', 'appointment:id,doctor_id,scheduled_at', 'appointment.doctor:id,name')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(QueueEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'appointment_id' => $entry->appointment_id,
            'patient_id' => $entry->patient_id,
            'patient_name' => $entry->patient?->name,
            'mrn' => $entry->patient?->mrn,
            'doctor_name' => $entry->appointment?->doctor?->name,
            'status' => $entry->status,
            'checked_in_at' => $entry->checked_in_at?->toIso8601String(),
        ];
    }
}
