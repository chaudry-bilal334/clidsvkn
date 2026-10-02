<?php

namespace App\Http\Controllers\Api;

use App\Events\AppointmentStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function __construct(private readonly AppointmentService $appointments) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', Appointment::STATUSES)],
            'doctor_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = Appointment::query()
            ->with(['patient', 'doctor:id,name'])
            ->orderByDesc('scheduled_at');

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($doctorId = $validated['doctor_id'] ?? null) {
            $query->where('doctor_id', $doctorId);
        }

        if ($from = $validated['from'] ?? null) {
            $query->whereDate('scheduled_at', '>=', $from);
        }

        if ($to = $validated['to'] ?? null) {
            $query->whereDate('scheduled_at', '<=', $to);
        }

        return $this->ok(
            $query->get()->map(fn (Appointment $appointment) => $this->present($appointment))->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $fields = $this->validateAppointment($request);

        /** @var User $actor */
        $actor = $request->user();

        $appointment = $this->appointments->book($fields, $actor);

        $this->announce($appointment, Appointment::STATUS_BOOKED);

        return $this->created($this->present($appointment->load(['patient', 'doctor:id,name'])));
    }

    /**
     * Move an appointment along the PRD §6 state machine.
     *
     * PATCH /api/v1/appointments/{appointment}/status
     */
    public function status(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', Appointment::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $target = $validated['status'];
        $reason = $validated['reason'] ?? null;

        // A cancellation must always carry a reason: it is the only record of
        // why a booked slot was released.
        if ($target === Appointment::STATUS_CANCELLED && blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => ['A cancellation reason is required.'],
            ]);
        }

        $updated = $this->appointments->transition($appointment, $target, $actor, $reason);

        $this->announce($updated, $target);

        return $this->ok($this->present($updated->load(['patient', 'doctor:id,name'])));
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', Appointment::STATUSES)],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'doctor_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'Doctor')],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! isset($validated['status']) && ! isset($validated['scheduled_at']) && ! isset($validated['doctor_id'])
            && ! isset($validated['reason']) && ! isset($validated['notes'])) {
            return $this->error('Nothing to update.', 422, null, [
                'appointment' => ['Supply at least one of: status, scheduled_at, doctor_id, reason, notes.'],
            ]);
        }

        /** @var User $actor */
        $actor = $request->user();

        // Slot moves go through the service so the availability check and the
        // database backstop both apply; status goes through the state machine.
        $updated = $appointment;

        if (isset($validated['scheduled_at']) || isset($validated['doctor_id'])) {
            $updated = $this->appointments->reschedule(
                $appointment,
                $validated['scheduled_at'] ?? $appointment->scheduled_at->format('Y-m-d H:i:s'),
                $actor,
                isset($validated['doctor_id']) ? (int) $validated['doctor_id'] : null,
            );
        }

        if (array_key_exists('reason', $validated) || array_key_exists('notes', $validated)) {
            $details = array_filter(
                ['reason' => $validated['reason'] ?? null, 'notes' => $validated['notes'] ?? null],
                fn (mixed $value): bool => $value !== null,
            );

            if ($details !== []) {
                $updated = $this->appointments->updateDetails($updated, $details, $actor);
            }
        }

        $targetStatus = $validated['status'] ?? $updated->status;

        if (isset($validated['status']) && $validated['status'] !== $appointment->status) {
            $updated = $this->appointments->transition(
                $updated,
                $targetStatus,
                $actor,
                $validated['cancellation_reason'] ?? null,
            );
        }

        $this->announce($updated, (string) $targetStatus);

        return $this->ok($this->present($updated->load(['patient', 'doctor:id,name'])));
    }

    /**
     * @return array{patient_id: int, doctor_id: int, scheduled_at: string, reason: string|null, notes: string|null}
     */
    private function validateAppointment(Request $request): array
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'doctor_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'Doctor')],
            'scheduled_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
        ]);

        return [
            'patient_id' => (int) $validated['patient_id'],
            'doctor_id' => (int) $validated['doctor_id'],
            'scheduled_at' => $validated['scheduled_at'],
            'reason' => $validated['reason'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];
    }

    /**
     * Broadcast a status change to the staff dashboards.
     */
    private function announce(Appointment $appointment, string $status): void
    {
        AppointmentStatusChanged::dispatch(
            ['id' => $appointment->id, 'time' => $appointment->scheduled_at?->format('H:i')],
            $status,
            $appointment->patient?->name ?? 'Patient',
            $appointment->doctor?->name,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'patient_name' => $appointment->patient?->name ?? 'Unknown Patient',
            'doctor_id' => $appointment->doctor_id,
            'doctor_name' => $appointment->doctor?->name ?? null,
            'scheduled_at' => $appointment->scheduled_at?->toDateTimeString(),
            'date' => $appointment->scheduled_at?->toDateString(),
            'time' => $appointment->scheduled_at?->format('H:i'),
            'status' => $appointment->status,
            'allowed_transitions' => $appointment->allowedTransitions(),
            'status_changed_at' => $appointment->status_changed_at?->toIso8601String(),
            'cancellation_reason' => $appointment->cancellation_reason,
            'reason' => $appointment->reason,
            'notes' => $appointment->notes,
        ];
    }
}
