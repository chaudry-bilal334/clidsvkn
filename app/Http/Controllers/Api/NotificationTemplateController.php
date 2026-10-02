<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class NotificationTemplateController extends Controller
{
    /**
     * List notification templates, optionally filtered by channel/status.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['nullable', Rule::in(NotificationTemplate::CHANNELS)],
            'status' => ['nullable', Rule::in(['pending', 'approved'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $templates = NotificationTemplate::query()
            ->when($validated['channel'] ?? null, fn ($q, string $channel) => $q->where('channel', $channel))
            ->when($validated['status'] ?? null, fn ($q, string $status) => $q->where('is_approved', $status === 'approved'))
            ->latest('created_at')
            ->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'success' => true,
            'data' => $templates->through(fn (NotificationTemplate $template): array => $this->present($template))->all(),
            'meta' => [
                'page' => $templates->currentPage(),
                'per_page' => $templates->perPage(),
                'total' => $templates->total(),
                'last_page' => $templates->lastPage(),
            ],
        ]);
    }

    /**
     * Create a new template in draft (unapproved) state.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        /** @var User $actor */
        $actor = Auth::user();

        $template = NotificationTemplate::create($data + ['created_by' => $actor->id]);

        AuditLog::record($actor, 'notification_template.created', $template, newValues: [
            'channel' => $template->channel,
            'name' => $template->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notification template created.',
            'data' => $this->present($template),
        ], 201);
    }

    /**
     * Show a single template.
     */
    public function show(NotificationTemplate $template): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->present($template),
        ]);
    }

    /**
     * Update a template. Approved templates can be edited; the edit keeps
     * their approved state (a correction workflow logs the change).
     */
    public function update(Request $request, NotificationTemplate $template): JsonResponse
    {
        $data = $this->validated($request, $template);

        if ($template->is_approved) {
            $data['is_approved'] = false;
            $data['approved_by'] = null;
            $data['approved_at'] = null;
        }

        $template->update($data);

        /** @var User $actor */
        $actor = Auth::user();

        AuditLog::record($actor, 'notification_template.updated', $template, oldValues: [
            'channel' => $template->getOriginal('channel'),
            'name' => $template->getOriginal('name'),
        ], newValues: [
            'channel' => $template->channel,
            'name' => $template->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notification template updated.',
            'data' => $this->present($template),
        ]);
    }

    /**
     * Soft-delete a template. Approved templates must be rejected first so a
     * reviewed message is never silently removed.
     */
    public function destroy(NotificationTemplate $template): JsonResponse
    {
        if ($template->is_approved) {
            return response()->json([
                'success' => false,
                'message' => 'Approved templates cannot be deleted; reject the template first.',
                'data' => null,
            ], 422);
        }

        $template->delete();

        AuditLog::record(Auth::user(), 'notification_template.deleted', $template, oldValues: [
            'channel' => $template->channel,
            'name' => $template->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notification template deleted.',
            'data' => null,
        ]);
    }

    /**
     * Render a draft body with sample data so staff can preview the exact
     * output (including placeholders) before saving.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::in(NotificationTemplate::CHANNELS)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'data' => ['nullable', 'array'],
        ]);

        $data = ($validated['data'] ?? []) + NotificationTemplate::PREVIEW_DATA;

        $subject = isset($validated['subject']) && $validated['subject'] !== ''
            ? strtr($validated['subject'], array_combine(
                array_map(fn (string $key): string => '{{'.$key.'}}', array_keys($data)),
                array_map('strval', $data),
            ))
            : null;

        $body = strtr($validated['body'], array_combine(
            array_map(fn (string $key): string => '{{'.$key.'}}', array_keys($data)),
            array_map('strval', $data),
        ));

        return response()->json([
            'success' => true,
            'data' => [
                'subject' => $subject,
                'body' => $body,
            ],
        ]);
    }

    /**
     * Render a stored template with the local sample data (never sends).
     */
    public function previewTemplate(Request $request, NotificationTemplate $template): JsonResponse
    {
        $validated = $request->validate([
            'data' => ['nullable', 'array'],
        ]);

        $data = ($validated['data'] ?? []) + NotificationTemplate::PREVIEW_DATA;

        return response()->json([
            'success' => true,
            'data' => [
                'subject' => $template->renderSubject($data),
                'body' => $template->renderBody($data),
            ],
        ]);
    }

    /**
     * Approve a template for sending. Records the approving actor and time.
     */
    public function approve(NotificationTemplate $template): JsonResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        $template->update([
            'is_approved' => true,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        AuditLog::record($actor, 'notification_template.approved', $template, newValues: [
            'name' => $template->name,
            'channel' => $template->channel,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notification template approved.',
            'data' => $this->present($template),
        ]);
    }

    /**
     * Reject a template: returns it to the pending state and clears the
     * approver identity, mirroring the reference-data workflow.
     */
    public function reject(NotificationTemplate $template): JsonResponse
    {
        $template->update([
            'is_approved' => false,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        AuditLog::record(Auth::user(), 'notification_template.rejected', $template, newValues: [
            'name' => $template->name,
            'channel' => $template->channel,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notification template reset to pending.',
            'data' => $this->present($template),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?NotificationTemplate $ignore = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('notification_templates', 'name')
                    ->where('channel', $request->input('channel'))
                    ->ignore($ignore?->id),
            ],
            'channel' => ['required', Rule::in(NotificationTemplate::CHANNELS)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(NotificationTemplate $template): array
    {
        return [
            'id' => $template->id,
            'channel' => $template->channel,
            'name' => $template->name,
            'subject' => $template->subject,
            'body' => $template->body,
            'is_approved' => $template->is_approved,
            'approved_by' => $template->approvedBy?->name,
            'approved_at' => $template->approved_at?->toISO8601String(),
            'created_by' => $template->createdBy?->name,
            'created_at' => $template->created_at?->toISO8601String(),
        ];
    }
}
