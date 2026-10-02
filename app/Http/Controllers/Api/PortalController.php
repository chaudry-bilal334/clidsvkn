<?php

namespace App\Http\Controllers\Api;

use App\Events\AppointmentStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\Prescription;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\DoctorAvailabilityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortalController extends Controller
{
    /**
     * The portal may expose these consent channels for self-service updates.
     *
     * @var array<int, string>
     */
    private const CONSENT_CHANNELS = [
        User::CONSENT_APPOINTMENTS,
        User::CONSENT_LOW_STOCK,
        User::CONSENT_SECURITY,
    ];

    /**
     * Statuses the portal patient may see in their upcoming appointments.
     *
     * Everything still holding a slot, so the patient can see an in-progress
     * visit as well as a future one. Cancelled and no-show are excluded.
     *
     * @var array<int, string>
     */
    private const VISIBLE_STATUSES = [
        Appointment::STATUS_BOOKED,
        Appointment::STATUS_CONFIRMED,
        Appointment::STATUS_ARRIVED,
        Appointment::STATUS_WAITING,
        Appointment::STATUS_IN_CONSULTATION,
    ];

    public function __construct(private readonly DoctorAvailabilityService $availability) {}

    public function appointments(Request $request): JsonResponse
    {
        $appointments = Appointment::query()
            ->where('patient_id', $this->patientId($request))
            ->whereIn('status', self::VISIBLE_STATUSES)
            ->where('scheduled_at', '>=', now())
            ->with('doctor:id,name')
            ->orderBy('scheduled_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $appointments->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
                'status' => $appointment->status,
                'reason' => $appointment->reason,
                'doctor_name' => $appointment->doctor?->name ?? 'TBC',
            ])->values(),
        ]);
    }

    /**
     * Patients request a new appointment for an active doctor at a preferred
     * branch and time slot. The booking is created as a "requested" hold that
     * staff confirm or modify from the appointments screen.
     */
    public function requestAppointment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doctor_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'Doctor')],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'scheduled_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $patientId = $this->patientId($request);

        $this->availability->assertSlotAvailable((int) $validated['doctor_id'], $validated['scheduled_at']);

        $appointment = Appointment::create([
            'patient_id' => $patientId,
            'doctor_id' => (int) $validated['doctor_id'],
            'branch_id' => (int) $validated['branch_id'],
            'scheduled_at' => $validated['scheduled_at'],
            // A portal request lands on the PRD §6 `booked` rung: it is a real
            // reservation and must hold the slot against double booking. The
            // front desk moves it to `confirmed`.
            'status' => Appointment::STATUS_BOOKED,
            'status_changed_at' => now(),
            'reason' => $validated['reason'] ?? null,
        ]);

        AppointmentStatusChanged::dispatch(
            ['id' => $appointment->id, 'time' => $appointment->scheduled_at?->format('H:i')],
            $appointment->status,
            $appointment->patient?->name ?? 'Patient',
            $appointment->doctor?->name,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $appointment->id,
                'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
                'status' => $appointment->status,
                'reason' => $appointment->reason,
                'doctor_name' => $appointment->doctor?->name ?? 'TBC',
                'branch_id' => $appointment->branch_id,
            ],
        ], 201);
    }

    public function invoices(Request $request): JsonResponse
    {
        $invoices = Invoice::query()
            ->where('patient_id', $this->patientId($request))
            ->orderByDesc('issued_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'total' => (float) $invoice->total,
                'balance_due' => (float) $invoice->balance_due,
                'status' => $invoice->status,
            ])->values(),
        ]);
    }

    public function invoicePdf(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->patient_id === $this->patientId($request), 403);

        $invoice->load(['items', 'payments']);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'clinic' => config('clinic'),
        ])->setPaper('a4');

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }

    public function prescriptions(Request $request): JsonResponse
    {
        $prescriptions = Prescription::query()
            ->where('patient_id', $this->patientId($request))
            ->with('items')
            ->orderByDesc('issued_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $prescriptions->map(fn (Prescription $prescription) => [
                'id' => $prescription->id,
                'prescription_number' => $prescription->prescription_number,
                'doctor_name' => $prescription->doctor_name,
                'issued_at' => $prescription->issued_at?->toIso8601String(),
                'status' => $prescription->status,
                'notes' => $prescription->notes,
                'items' => $prescription->items->map(fn ($item) => [
                    'medicine_name' => $item->medicine_name,
                    'dosage' => $item->dosage,
                    'frequency' => $item->frequency,
                    'duration' => $item->duration,
                    'instructions' => $item->instructions,
                    'quantity' => $item->quantity,
                ])->values(),
            ])->values(),
        ]);
    }

    public function prescriptionPdf(Request $request, Prescription $prescription)
    {
        abort_unless($prescription->patient_id === $this->patientId($request), 403);

        $prescription->load('items');

        $pdf = Pdf::loadView('pdf.prescription', [
            'prescription' => $prescription,
            'clinic' => config('clinic'),
        ])->setPaper('a4');

        return $pdf->download("prescription-{$prescription->prescription_number}.pdf");
    }

    public function consent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $preferences = $user->notification_preferences ?? [];

        $data = [];
        foreach (self::CONSENT_CHANNELS as $channel) {
            $data[$channel] = $preferences[$channel] ?? true;
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function labResults(Request $request): JsonResponse
    {
        // PRD §10/§12: a patient may only read results the lab has verified. A
        // pending result is still in the workflow — releasing it early would
        // put an unconfirmed value in front of a patient as if it were final.
        $labOrders = LabOrder::query()
            ->where('patient_id', $this->patientId($request))
            ->whereIn('verification_state', LabOrder::RELEASABLE_VERIFICATION_STATES)
            ->whereNotNull('result')
            ->with(['recordedBy:id,name', 'verifier:id,name'])
            ->orderByDesc('verified_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $labOrders->map(fn (LabOrder $labOrder) => [
                'id' => $labOrder->id,
                'test_name' => $labOrder->test_name,
                'result' => $labOrder->result,
                'status' => $labOrder->status,
                'recorded_at' => $labOrder->recorded_at?->toIso8601String(),
                'recorded_by' => $labOrder->recordedBy?->name,
                'verification_state' => $labOrder->verification_state,
                'verifier_name' => $labOrder->verifier?->name,
                'verified_at' => $labOrder->verified_at?->toIso8601String(),
                'finalized_at' => $labOrder->finalized_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The patient portal surfaces account-level security events only
        // (e.g. login failures). Staff-only notes and audit details are never
        // exposed here per PRD §10.
        $notifications = SecurityEvent::where('user_id', $user->id)
            ->latest()
            ->take(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $notifications->map(fn (SecurityEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'severity' => $event->severity,
                'message' => ucfirst(str_replace('_', ' ', $event->type)),
                'created_at' => $event->created_at?->toIso8601String(),
                // Security events are system-generated; there is no per-user read flag.
                'read' => false,
            ])->values(),
        ]);
    }

    public function updateConsent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'appointments' => ['required', 'boolean'],
            'low_stock' => ['required', 'boolean'],
            'security' => ['required', 'boolean'],
        ]);

        $preferences = $user->notification_preferences ?? [];
        foreach (self::CONSENT_CHANNELS as $channel) {
            $preferences[$channel] = (bool) $validated[$channel];
        }

        $user->notification_preferences = $preferences;
        $user->save();

        return response()->json(['success' => true, 'data' => $preferences]);
    }

    private function patientId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        abort_if($user->patient_id === null, 403, 'No patient record is linked to this account.');

        return (int) $user->patient_id;
    }
}
