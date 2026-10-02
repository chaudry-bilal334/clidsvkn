<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFollowupRequest;
use App\Http\Requests\UpdateFollowupRequest;
use App\Models\Followup;
use App\Services\FollowupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FollowupController extends Controller
{
    public function __construct(private readonly FollowupService $followups) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Followup::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(Followup::statuses())],
            'patient_id' => ['nullable', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
        ]);

        $query = Followup::query()
            ->with(['patient:id,name', 'lead:id,name', 'encounter:id,id', 'assignee:id,name', 'completer:id,name'])
            ->orderBy('due_at')
            ->orderBy('id');

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($patientId = $validated['patient_id'] ?? null) {
            $query->where('patient_id', $patientId);
        }

        if ($leadId = $validated['lead_id'] ?? null) {
            $query->where('lead_id', $leadId);
        }

        if ($assigneeId = $validated['assigned_to'] ?? null) {
            $query->where('assigned_to', $assigneeId);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn (Followup $followup) => $this->present($followup))->values(),
        ]);
    }

    public function store(StoreFollowupRequest $request): JsonResponse
    {
        $this->authorize('create', Followup::class);

        $followup = Followup::create([
            'patient_id' => $request->integer('patient_id') ?: null,
            'lead_id' => $request->integer('lead_id') ?: null,
            'encounter_id' => $request->integer('encounter_id') ?: null,
            'created_by' => $request->user()?->id,
            'assigned_to' => $request->integer('assigned_to') ?: null,
            'due_at' => $request->input('due_at'),
            'type' => $request->input('type', Followup::TYPE_GENERAL),
            'status' => Followup::STATUS_PENDING,
            'notes' => $request->input('notes'),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->present($followup->load(['patient:id,name', 'lead:id,name', 'assignee:id,name'])),
        ], 201);
    }

    public function update(UpdateFollowupRequest $request, Followup $followup): JsonResponse
    {
        $this->authorize('update', $followup);

        if ($followup->status !== Followup::STATUS_PENDING) {
            abort(422, 'Only pending follow-ups can be edited.');
        }

        $followup->fill($request->validated());
        $followup->save();

        return response()->json([
            'success' => true,
            'data' => $this->present($followup->load(['patient:id,name', 'lead:id,name', 'assignee:id,name'])),
        ]);
    }

    public function complete(Request $request, Followup $followup): JsonResponse
    {
        $this->authorize('complete', $followup);

        $followup = $this->followups->complete($followup, $request->user());

        return response()->json([
            'success' => true,
            'data' => $this->present($followup->load(['patient:id,name', 'lead:id,name', 'assignee:id,name', 'completer:id,name'])),
        ]);
    }

    public function cancel(Request $request, Followup $followup): JsonResponse
    {
        $this->authorize('cancel', $followup);

        $followup = $this->followups->cancel($followup, $request->user());

        return response()->json([
            'success' => true,
            'data' => $this->present($followup->load(['patient:id,name', 'lead:id,name', 'assignee:id,name'])),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Followup $followup): array
    {
        $overdue = $followup->status === Followup::STATUS_PENDING
            && $followup->due_at !== null
            && $followup->due_at->isPast();

        return [
            'id' => $followup->id,
            'patient_id' => $followup->patient_id,
            'patient_name' => $followup->patient?->name,
            'lead_id' => $followup->lead_id,
            'lead_name' => $followup->lead?->name,
            'encounter_id' => $followup->encounter_id,
            'type' => $followup->type,
            'status' => $followup->status,
            'overdue' => $overdue,
            'due_at' => $followup->due_at?->toIso8601String(),
            'assigned_to' => $followup->assigned_to,
            'assigned_to_name' => $followup->assignee?->name,
            'notes' => $followup->notes,
            'completed_at' => $followup->completed_at?->toIso8601String(),
            'completed_by' => $followup->completed_by,
            'completed_by_name' => $followup->completer?->name,
            'created_at' => $followup->created_at?->toIso8601String(),
        ];
    }
}
