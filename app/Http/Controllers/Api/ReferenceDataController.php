<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReferenceDataReadinessService;
use App\Services\ReferenceDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferenceDataController extends Controller
{
    private const TYPES = ['allergens', 'drug_interactions', 'dosage_rules'];

    public function __construct(private readonly ReferenceDataService $referenceData) {}

    /**
     * Report whether the clinical safety ruleset is loaded and being enforced.
     *
     * Drives the admin banner in the reference data screen: a clinic that has
     * not approved any allergy/dosage/interaction data cannot detect safety
     * problems, so it needs to be visible before anyone tries to prescribe.
     */
    public function readiness(ReferenceDataReadinessService $readiness): JsonResponse
    {
        $status = $readiness->status();

        return response()->json([
            'success' => true,
            'data' => [
                'ready' => $status['ready'],
                'enforcement_enabled' => $readiness->enforcementEnabled(),
                'missing' => $readiness->missingTypes(),
                'types' => $status['types'],
            ],
        ]);
    }

    /**
     * List reference data awaiting approval.
     */
    public function pending(Request $request): JsonResponse
    {
        $type = $request->query('type');

        if ($type !== null && ! in_array($type, self::TYPES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reference data type.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->referenceData->pending($type),
        ]);
    }

    /**
     * Approve a reference data item so clinical safety checks start honouring it.
     */
    public function approve(Request $request, string $type, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $model = $this->referenceData->approve($type, $id, $actor);

        return response()->json([
            'success' => true,
            'message' => str_replace('_', ' ', $type).' approved.',
            'data' => [
                'id' => $model->id,
                'is_approved' => $model->is_approved,
                'approved_by' => $model->approvedBy?->name,
                'approved_at' => $model->approved_at?->toISO8601String(),
            ],
        ]);
    }

    /**
     * Return a reference data item to the pending queue.
     */
    public function reject(Request $request, string $type, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $model = $this->referenceData->reject($type, $id, $actor);

        return response()->json([
            'success' => true,
            'message' => str_replace('_', ' ', $type).' reset to pending.',
            'data' => [
                'id' => $model->id,
                'is_approved' => $model->is_approved,
                'approved_by' => $model->approved_by,
                'approved_at' => $model->approved_at,
            ],
        ]);
    }
}
