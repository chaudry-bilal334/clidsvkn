<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMedicalDocumentRequest;
use App\Models\MedicalDocument;
use App\Models\Patient;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\QueryCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MedicalDocumentController extends Controller
{
    public function __construct(
        private readonly QueryCacheService $queryCache,
        private readonly DocumentService $documents,
    ) {}

    /**
     * List a patient's medical documents.
     *
     * Staff roles (permission documents.view) may list any patient. Patients
     * are only permitted to view their own records through the portal routes.
     */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->authorizePatientAccess($request->user(), $patient);

        $documents = $this->queryCache->rememberGroup(
            'medical.documents',
            'patient:'.$patient->id,
            fn (): array => MedicalDocument::query()
                ->where('patient_id', $patient->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (MedicalDocument $document): array => $this->payload($document))
                ->values()
                ->all(),
            300,
        );

        return response()->json([
            'success' => true,
            'data' => $documents,
        ]);
    }

    /**
     * Store a medical document for a patient on the private medical disk.
     *
     * POST /api/v1/patients/{patient}/documents
     */
    public function store(StoreMedicalDocumentRequest $request, Patient $patient): JsonResponse
    {
        $this->authorizePatientAccess($request->user(), $patient);

        $document = $this->documents->upload(
            $patient,
            $request->user(),
            $request->file('file'),
            $request->validated('category'),
            $request->input('notes'),
        );

        $this->queryCache->forgetGroup('medical.documents');

        return response()->json([
            'success' => true,
            'data' => $this->payload($document),
        ], 201);
    }

    /**
     * Re-categorise a stored document.
     *
     * PUT /api/v1/documents/{document}
     */
    public function update(Request $request, MedicalDocument $document): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['sometimes', 'string', 'in:lab_report,external_prescription,insurance_card,other'],
        ]);

        $document = $this->documents->update($document, $request->user(), $validated);

        $this->queryCache->forgetGroup('medical.documents');

        return response()->json([
            'success' => true,
            'data' => $this->payload($document),
        ]);
    }

    /**
     * Retire a document and remove its stored file.
     *
     * DELETE /api/v1/documents/{document}
     */
    public function destroy(Request $request, MedicalDocument $document): JsonResponse
    {
        $this->documents->delete($document, $request->user());

        $this->queryCache->forgetGroup('medical.documents');

        return response()->json(['success' => true, 'message' => 'Document deleted.']);
    }

    /**
     * Stream a medical document download.
     *
     * Ownership and permission checks: patients may only download their own
     * documents; staff roles holding documents.view may download any.
     *
     * GET /api/v1/documents/{document}/download
     * GET /api/v1/portal/documents/{document}/download
     */
    public function download(Request $request, MedicalDocument $document): BinaryFileResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role === 'Patient') {
            abort_if($document->patient_id !== $user->patient_id, 403, 'You are not authorized to download this document.');
        }

        $disk = Storage::disk('medical');

        abort_if(! $disk->exists($document->file_path), 404, 'The requested document could not be found.');

        return response()->download(
            $disk->path($document->file_path),
            $document->original_name,
        );
    }

    /**
     * Portal: list the authenticated patient's own documents.
     *
     * GET /api/v1/portal/documents
     */
    public function portalIndex(Request $request): JsonResponse
    {
        return $this->index($request, $this->portalPatient($request));
    }

    /**
     * Portal: upload a document for the authenticated patient.
     *
     * POST /api/v1/portal/documents
     */
    public function portalStore(StoreMedicalDocumentRequest $request): JsonResponse
    {
        return $this->store($request, $this->portalPatient($request));
    }

    /**
     * Resolve the authenticated portal user's linked patient record.
     */
    private function portalPatient(Request $request): Patient
    {
        $patientId = $request->user()?->patient_id;

        abort_if($patientId === null, 403, 'No patient record is linked to this account.');

        $patient = Patient::query()->find($patientId);

        abort_if($patient === null, 404, 'No patient record is linked to this account.');

        return $patient;
    }

    /**
     * A patient may only access their own documents; staff bypass this check.
     */
    private function authorizePatientAccess(?User $user, Patient $patient): void
    {
        abort_if($user === null, 403, 'Authentication required.');

        abort_if(
            $user->role === 'Patient' && $user->patient_id !== $patient->id,
            403,
            'You are not authorized to access these documents.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(MedicalDocument $document): array
    {
        return [
            'id' => $document->id,
            'category' => $document->category,
            'original_name' => $document->original_name,
            'file_path' => $document->file_path,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'created_at' => $document->created_at?->toDateTimeString(),
        ];
    }
}
