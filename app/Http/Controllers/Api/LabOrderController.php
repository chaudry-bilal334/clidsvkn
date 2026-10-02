<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabOrder;
use App\Models\User;
use App\Services\LabVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabOrderController extends Controller
{
    public function __construct(private readonly LabVerificationService $labVerification) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['nullable', 'integer'],
        ]);

        $query = LabOrder::query()
            ->with('recordedBy:id,name', 'verifier:id,name', 'patient:id,name,mrn')
            ->orderByDesc('created_at');

        if ($patientId = $validated['patient_id'] ?? null) {
            $query->where('patient_id', $patientId);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn (LabOrder $labOrder) => $this->present($labOrder))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'test_name' => ['required', 'string', 'max:150'],
        ]);

        $labOrder = LabOrder::create([
            'patient_id' => (int) $validated['patient_id'],
            'test_name' => $validated['test_name'],
            'status' => LabOrder::STATUS_PENDING,
            'verification_state' => LabOrder::VERIFICATION_PENDING,
        ]);

        $labOrder->load('recordedBy:id,name', 'verifier:id,name');

        return response()->json([
            'success' => true,
            'data' => $this->present($labOrder),
        ], 201);
    }

    public function update(Request $request, LabOrder $labOrder): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', self::STATUSES())],
            'result' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $result = $validated['result'] ?? null;
        $status = $validated['status'] ?? $labOrder->status;

        // A verified result is immutable here. Changing it must go through the
        // amendment endpoint, which mandates a reason and records the original
        // values (PRD §7, §9).
        if ($labOrder->verification_state === LabOrder::VERIFICATION_VERIFIED) {
            if ($result !== null && $result !== $labOrder->result) {
                return $this->error(
                    'This result has already been verified. Use the amendment endpoint with a reason to change it.',
                    422,
                    null,
                    ['result' => ['Use POST /lab-orders/'.$labOrder->id.'/amend with a mandatory reason.']],
                );
            }

            // Even the remaining status-only write is audited and transactional.
            $this->labVerification->changeStatus($labOrder, $user, $status);

            return $this->ok($this->present($labOrder->refresh()->load('recordedBy:id,name', 'verifier:id,name')));
        }

        // A supplied result (or a move to `completed`) is a result-recording
        // event, not a generic field update — route it through the service so
        // it is transactional and audited.
        if ($result !== null || $status === LabOrder::STATUS_COMPLETED) {
            if ($result === null) {
                return $this->error('A result is required before the order can be completed.', 422, null, [
                    'result' => ['A result is required before the order can be completed.'],
                ]);
            }

            $labOrder = $this->labVerification->recordResult($labOrder, $user, $result, $status);

            return $this->ok($this->present($labOrder->load('recordedBy:id,name', 'verifier:id,name')));
        }

        $this->labVerification->changeStatus($labOrder, $user, $status);

        return $this->ok($this->present($labOrder->refresh()->load('recordedBy:id,name', 'verifier:id,name')));
    }

    public function collectSample(Request $request, LabOrder $labOrder): JsonResponse
    {
        $validated = $request->validate([
            'sample_identifier' => ['nullable', 'string', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $collected = $this->labVerification->collectSample($labOrder, $user, $validated['sample_identifier'] ?? null);

        return response()->json([
            'success' => true,
            'data' => $this->present($collected->load('recordedBy:id,name', 'verifier:id,name', 'sampleCollectedBy:id,name')),
        ]);
    }

    public function finalize(Request $request, LabOrder $labOrder): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $finalized = $this->labVerification->finalize($labOrder, $user);

        return response()->json([
            'success' => true,
            'data' => $this->present($finalized->load('recordedBy:id,name', 'verifier:id,name')),
        ]);
    }

    public function verify(Request $request, LabOrder $labOrder): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $verified = $this->labVerification->verify($labOrder, $user);

        return response()->json([
            'success' => true,
            'data' => $this->present($verified->load('recordedBy:id,name', 'verifier:id,name')),
        ]);
    }

    public function amend(Request $request, LabOrder $labOrder): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'result' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $amended = $this->labVerification->amend($labOrder, $user, $validated['reason'], $validated['result'] ?? null);

        return response()->json([
            'success' => true,
            'data' => $this->present($amended->load('recordedBy:id,name', 'verifier:id,name')),
        ]);
    }

    /**
     * The lab order statuses the workflow accepts.
     *
     * @return list<string>
     */
    private static function STATUSES(): array
    {
        return [
            LabOrder::STATUS_PENDING,
            LabOrder::STATUS_SAMPLE_COLLECTED,
            LabOrder::STATUS_IN_PROGRESS,
            LabOrder::STATUS_COMPLETED,
            LabOrder::STATUS_CANCELLED,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LabOrder $labOrder): array
    {
        return [
            'id' => $labOrder->id,
            'patient_id' => $labOrder->patient_id,
            'patient_name' => $labOrder->patient?->name,
            'mrn' => $labOrder->patient?->mrn,
            'test_name' => $labOrder->test_name,
            'status' => $labOrder->status,
            'result' => $labOrder->result,
            'sample_collected_at' => $labOrder->sample_collected_at?->toIso8601String(),
            'sample_collected_by' => $labOrder->sample_collected_by,
            'sample_collected_by_name' => $labOrder->sampleCollectedBy?->name,
            'sample_identifier' => $labOrder->sample_identifier,
            'recorded_at' => $labOrder->recorded_at?->toIso8601String(),
            'recorded_by' => $labOrder->recorded_by,
            'recorded_by_name' => $labOrder->recordedBy?->name,
            'verification_state' => $labOrder->verification_state,
            'verifier_id' => $labOrder->verifier_id,
            'verifier_name' => $labOrder->verifier?->name,
            'verified_at' => $labOrder->verified_at?->toIso8601String(),
            'finalized_at' => $labOrder->finalized_at?->toIso8601String(),
            'is_finalized' => $labOrder->finalized_at !== null,
            'patient_releasable' => $labOrder->isReleasableToPatient(),
        ];
    }
}
