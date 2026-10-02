<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataExportRequest;
use App\Models\Patient;
use App\Services\PrivacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ComplianceController extends Controller
{
    public function __construct(private readonly PrivacyService $privacy) {}

    public function requestExport(Request $request): JsonResponse
    {
        $this->authorize('manageExports', DataExportRequest::class);

        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
        ]);

        $exportRequest = DataExportRequest::create([
            'patient_id' => $validated['patient_id'],
            'requested_by' => $request->user()->id,
            'status' => DataExportRequest::STATUS_PENDING,
        ]);

        // Queue the export generation
        $this->privacy->generatePatientExport($exportRequest);

        return response()->json([
            'success' => true,
            'data' => $this->presentExport($exportRequest->refresh()),
        ], 201);
    }

    public function listExports(Request $request): JsonResponse
    {
        $this->authorize('viewExports', DataExportRequest::class);

        \Log::info('listExports called', [
            'user' => $request->user()?->id,
            'user_role' => $request->user()?->role,
        ]);

        $query = DataExportRequest::query()
            ->with(['patient:id,name', 'requester:id,name'])
            ->latest();

        \Log::info('listExports query', ['count' => $query->count()]);

        $validated = $request->validate([
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'status' => ['nullable', 'string', 'in:'.implode(',', [
                DataExportRequest::STATUS_PENDING,
                DataExportRequest::STATUS_PROCESSING,
                DataExportRequest::STATUS_COMPLETED,
                DataExportRequest::STATUS_FAILED,
            ])],
        ]);

        $query = DataExportRequest::query()
            ->with(['patient:id,name', 'requester:id,name'])
            ->latest();

        if ($patientId = $validated['patient_id'] ?? null) {
            $query->where('patient_id', $patientId);
        }

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        \Log::info('listExports filtered query', ['count' => $query->count()]);

        $exports = $query->paginate(15);

        \Log::info('listExports paginated', ['count' => $exports->count(), 'total' => $exports->total()]);

        return response()->json([
            'success' => true,
            'data' => $exports->items(),
            'meta' => [
                'page' => $exports->currentPage(),
                'per_page' => $exports->perPage(),
                'total' => $exports->total(),
                'last_page' => $exports->lastPage(),
            ],
        ]);
    }

    public function downloadExport(DataExportRequest $export): Response
    {
        $this->authorize('downloadExport', $export);

        \Log::info('downloadExport', [
            'export_id' => $export->id,
            'status' => $export->status,
            'file_path' => $export->file_path,
            'file_exists' => $export->file_path ? Storage::disk('local')->exists($export->file_path) : false,
        ]);

        if ($export->status !== DataExportRequest::STATUS_COMPLETED) {
            return response()->json([
                'success' => false,
                'message' => 'Export not ready for download.',
            ], 422);
        }

        if (! $export->file_path || ! Storage::disk('local')->exists($export->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Export file not found.',
            ], 404);
        }

        return response()->download(
            Storage::disk('local')->path($export->file_path),
            'patient-export-'.$export->patient_id.'.json'
        );
    }

    public function anonymizePatient(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('anonymize', $patient);

        $this->privacy->anonymizePatient($patient, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Patient data has been anonymized.',
            'data' => [
                'id' => $patient->id,
                'name' => $patient->name,
                'email' => $patient->email,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentExport(DataExportRequest $export): array
    {
        return [
            'id' => $export->id,
            'patient_id' => $export->patient_id,
            'patient_name' => $export->patient?->name,
            'requested_by' => $export->requested_by,
            'requester_name' => $export->requester?->name,
            'status' => $export->status,
            'file_path' => $export->file_path,
            'created_at' => $export->created_at?->toIso8601String(),
            'completed_at' => $export->status === DataExportRequest::STATUS_COMPLETED ? $export->updated_at?->toIso8601String() : null,
        ];
    }
}
