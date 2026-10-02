<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewAiContentDraftRequest;
use App\Http\Requests\StoreAiContentDraftRequest;
use App\Http\Requests\UpdateAiContentDraftRequest;
use App\Models\AiContentDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiContentDraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,pending_review,approved,rejected,published'],
            'content_type' => ['nullable', 'string'],
        ]);

        $query = AiContentDraft::query()
            ->with(['creator:id,name', 'submitter:id,name', 'reviewer:id,name'])
            ->orderByDesc('updated_at');

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($contentType = $validated['content_type'] ?? null) {
            $query->where('content_type', $contentType);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(StoreAiContentDraftRequest $request): JsonResponse
    {
        $aiContent = AiContentDraft::create([
            ...$request->validated(),
            'status' => AiContentDraft::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $aiContent->fresh(),
        ], 201);
    }

    public function show(AiContentDraft $aiContentDraft): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $aiContentDraft->load(['creator:id,name', 'submitter:id,name', 'reviewer:id,name']),
        ]);
    }

    public function update(UpdateAiContentDraftRequest $request, AiContentDraft $aiContentDraft): JsonResponse
    {
        if ($aiContentDraft->status !== AiContentDraft::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft content can be edited.',
            ], 422);
        }

        $aiContentDraft->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => $aiContentDraft->fresh(),
        ]);
    }

    public function destroy(AiContentDraft $aiContentDraft): JsonResponse
    {
        if (! in_array($aiContentDraft->status, [AiContentDraft::STATUS_DRAFT, AiContentDraft::STATUS_REJECTED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft or rejected content can be deleted.',
            ], 422);
        }

        $aiContentDraft->delete();

        return response()->json(['success' => true, 'message' => 'Draft deleted.']);
    }

    public function submit(AiContentDraft $aiContentDraft, Request $request): JsonResponse
    {
        if ($aiContentDraft->status !== AiContentDraft::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft content can be submitted for review.',
            ], 422);
        }

        $aiContentDraft->update([
            'status' => AiContentDraft::STATUS_PENDING_REVIEW,
            'submitted_by' => $request->user()->id,
            'submitted_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Content submitted for review.',
            'data' => $aiContentDraft->fresh(),
        ]);
    }

    public function review(ReviewAiContentDraftRequest $request, AiContentDraft $aiContentDraft): JsonResponse
    {
        if ($aiContentDraft->status !== AiContentDraft::STATUS_PENDING_REVIEW) {
            return response()->json([
                'success' => false,
                'message' => 'Only content pending review can be reviewed.',
            ], 422);
        }

        if ($request->user()->id === $aiContentDraft->submitted_by) {
            return response()->json([
                'success' => false,
                'message' => 'A reviewer cannot review their own submitted content.',
            ], 422);
        }

        $decision = $request->input('decision');

        $aiContentDraft->update([
            'status' => $decision === 'approve'
                ? AiContentDraft::STATUS_APPROVED
                : AiContentDraft::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => $decision === 'reject' ? $request->input('rejection_reason') : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => $decision === 'approve' ? 'Content approved.' : 'Content rejected.',
            'data' => $aiContentDraft->fresh(),
        ]);
    }

    public function publish(AiContentDraft $aiContentDraft): JsonResponse
    {
        if ($aiContentDraft->status !== AiContentDraft::STATUS_APPROVED) {
            return response()->json([
                'success' => false,
                'message' => 'Only approved content can be published; it is never auto-published.',
            ], 422);
        }

        $aiContentDraft->update([
            'status' => AiContentDraft::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Content published.',
            'data' => $aiContentDraft->fresh(),
        ]);
    }
}
