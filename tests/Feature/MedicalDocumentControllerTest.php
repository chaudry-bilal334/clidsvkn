<?php

use App\Models\AuditLog;
use App\Models\MedicalDocument;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

pest()->use(RefreshDatabase::class);

function mdc_staff(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

/**
 * Realistic bytes for allowed document extensions so the content-sniffing
 * guard accepts legitimate uploads.
 */
function mdc_file(string $name = 'report.pdf', int $kilobytes = 8): UploadedFile
{
    $content = str_ends_with(strtolower($name), '.pdf')
        ? '%PDF-1.4 '.str_repeat('0', max(1, $kilobytes) * 1024)
        : str_repeat('0', max(1, $kilobytes) * 1024);

    return UploadedFile::fake()->createWithContent($name, $content);
}

function mdc_patient(?User $staff = null): Patient
{
    $staff ??= mdc_staff();

    return Patient::factory()->create(['branch_id' => $staff->branch_id]);
}

beforeEach(function () {
    Storage::fake('medical');
});

test('a document can be uploaded for a patient', function () {
    $staff = mdc_staff();
    $patient = mdc_patient($staff);

    $response = $this->actingAs($staff)
        ->post("/api/v1/patients/{$patient->id}/documents", [
            'file' => mdc_file('lab report.pdf', 12),
            'category' => 'lab_report',
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.category', 'lab_report')
        ->assertJsonPath('data.original_name', 'lab report.pdf');

    $document = MedicalDocument::firstOrFail();

    Storage::disk('medical')->assertExists($document->file_path);

    $audit = AuditLog::query()->where('action', 'medical_document.created')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->auditable_id)->toBe($document->id);
});

test('document upload rejects a disallowed mime type', function () {
    $staff = mdc_staff();
    $patient = mdc_patient($staff);

    $this->actingAs($staff)
        ->post("/api/v1/patients/{$patient->id}/documents", [
            'file' => mdc_file('payload.exe', 4),
            'category' => 'other',
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);

    expect(MedicalDocument::count())->toBe(0);
});

test('patient documents can be listed', function () {
    $staff = mdc_staff();
    $patient = mdc_patient($staff);

    $this->actingAs($staff)
        ->post("/api/v1/patients/{$patient->id}/documents", [
            'file' => mdc_file('scan.pdf', 3),
            'category' => 'other',
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $this->actingAs($staff)
        ->getJson("/api/v1/patients/{$patient->id}/documents")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a document category can be updated', function () {
    $staff = mdc_staff();
    $patient = mdc_patient($staff);

    $this->actingAs($staff)
        ->post("/api/v1/patients/{$patient->id}/documents", [
            'file' => mdc_file('note.pdf', 1),
            'category' => 'other',
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $document = MedicalDocument::firstOrFail();

    $this->actingAs($staff)
        ->putJson("/api/v1/documents/{$document->id}", ['category' => 'external_prescription'])
        ->assertOk()
        ->assertJsonPath('data.category', 'external_prescription');

    expect($document->fresh()->category)->toBe('external_prescription');

    $audit = AuditLog::query()->where('action', 'medical_document.updated')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->old_values['category'])->toBe('other')
        ->and($audit->new_values['category'])->toBe('external_prescription');
});

test('a document can be deleted and its file removed', function () {
    $staff = mdc_staff();
    $patient = mdc_patient($staff);

    $this->actingAs($staff)
        ->post("/api/v1/patients/{$patient->id}/documents", [
            'file' => mdc_file('note.pdf', 1),
            'category' => 'other',
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $document = MedicalDocument::firstOrFail();
    $path = $document->file_path;

    $this->actingAs($staff)
        ->deleteJson("/api/v1/documents/{$document->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    Storage::disk('medical')->assertMissing($path);

    $deleted = MedicalDocument::withTrashed()->find($document->id);
    expect($deleted)->not->toBeNull()
        ->and($deleted->trashed())->toBeTrue();

    expect(AuditLog::query()->where('action', 'medical_document.deleted')->first())->not->toBeNull();
});

test('a stored document can be downloaded by staff', function () {
    $staff = mdc_staff();
    $patient = mdc_patient($staff);

    $this->actingAs($staff)
        ->post("/api/v1/patients/{$patient->id}/documents", [
            'file' => mdc_file('lab report.pdf', 2),
            'category' => 'lab_report',
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $document = MedicalDocument::firstOrFail();
    Storage::disk('medical')->put($document->file_path, 'report-bytes');

    $this->actingAs($staff)
        ->get("/api/v1/documents/{$document->id}/download")
        ->assertOk();
});
