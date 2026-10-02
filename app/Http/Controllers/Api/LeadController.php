<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommunicationRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\TransitionLeadRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Models\Communication;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function __construct(private readonly LeadService $leads) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(Lead::statuses())],
            'assigned_to' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Lead::query()
            ->with(['preferredDoctor:id,name', 'assignee:id,name'])
            ->orderByDesc('created_at');

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($assigneeId = $validated['assigned_to'] ?? null) {
            $query->where('assigned_to', $assigneeId);
        }

        if ($search = $validated['search'] ?? null) {
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('service_interest', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn (Lead $lead) => $this->present($lead))->values(),
        ]);
    }

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $this->authorize('create', Lead::class);

        $lead = Lead::create([
            'name' => $request->string('name')->toString(),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'service_interest' => $request->input('service_interest'),
            'preferred_doctor_id' => $request->integer('preferred_doctor_id') ?: null,
            'preferred_date' => $request->input('preferred_date'),
            'preferred_time' => $request->input('preferred_time'),
            'source' => $request->input('source', Lead::SOURCE_WALK_IN),
            'status' => Lead::STATUS_NEW,
            'notes' => $request->input('notes'),
            'assigned_to' => $request->integer('assigned_to') ?: null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->present($lead->load(['preferredDoctor:id,name', 'assignee:id,name'])),
        ], 201);
    }

    public function show(Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        return response()->json([
            'success' => true,
            'data' => $this->presentDetail($lead->load([
                'preferredDoctor:id,name',
                'assignee:id,name',
                'communications.user:id,name',
            ])),
        ]);
    }

    /**
     * Doctor and assignee picklists used by the CRM forms.
     *
     * Kept self-contained under the crm.view permission so that every CRM
     * role (including Accountant, who lacks appointments.view) can populate
     * the lead form without depending on other domains.
     */
    public function options(): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        return response()->json([
            'success' => true,
            'data' => [
                'doctors' => User::query()->where('role', 'Doctor')->orderBy('name')->get(['id', 'name']),
                'staff' => User::query()
                    ->whereIn('role', ['Admin', 'Doctor', 'Receptionist', 'Accountant'])
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ],
        ]);
    }

    public function update(UpdateLeadRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        $lead->fill($request->validated());
        $lead->save();

        return response()->json([
            'success' => true,
            'data' => $this->present($lead->load(['preferredDoctor:id,name', 'assignee:id,name'])),
        ]);
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $this->authorize('delete', $lead);

        $lead->delete();

        return response()->json(['success' => true, 'message' => 'Lead deleted.']);
    }

    public function transition(TransitionLeadRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('transition', $lead);

        $lead = $this->leads->transition(
            $lead,
            $request->input('status'),
            $request->user(),
            $request->input('notes'),
        );

        return response()->json([
            'success' => true,
            'data' => $this->present($lead->load(['preferredDoctor:id,name', 'assignee:id,name'])),
        ]);
    }

    public function storeCommunication(StoreCommunicationRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('recordCommunication', $lead);

        $communication = Communication::create([
            'lead_id' => $lead->id,
            'user_id' => $request->user()?->id,
            'channel' => $request->string('channel')->toString(),
            'summary' => $request->string('summary')->toString(),
            'notes' => $request->input('notes'),
            'occurred_at' => $request->input('occurred_at') ?? now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->presentCommunication($communication->load('user:id,name')),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'branch_id' => $lead->branch_id,
            'name' => $lead->name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'service_interest' => $lead->service_interest,
            'preferred_doctor_id' => $lead->preferred_doctor_id,
            'preferred_doctor_name' => $lead->preferredDoctor?->name,
            'preferred_date' => $lead->preferred_date?->toDateString(),
            'preferred_time' => $lead->preferred_time,
            'source' => $lead->source,
            'status' => $lead->status,
            'notes' => $lead->notes,
            'assigned_to' => $lead->assigned_to,
            'assigned_to_name' => $lead->assignee?->name,
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Lead $lead): array
    {
        return $this->present($lead) + [
            'communications' => $lead->communications
                ->map(fn (Communication $communication) => $this->presentCommunication($communication->load('user:id,name')))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCommunication(Communication $communication): array
    {
        return [
            'id' => $communication->id,
            'channel' => $communication->channel,
            'summary' => $communication->summary,
            'notes' => $communication->notes,
            'user_id' => $communication->user_id,
            'user_name' => $communication->user?->name,
            'occurred_at' => $communication->occurred_at?->toIso8601String(),
        ];
    }
}
