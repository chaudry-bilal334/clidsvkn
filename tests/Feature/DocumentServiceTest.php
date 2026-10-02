<?php

use App\Models\AuditLog;
use App\Models\MedicalDocument;
use App\Models\Patient;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

pest()->use(RefreshDatabase::class);

function doc_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function doc_patient(?User $doctor = null): Patient
{
    $doctor = $doctor ?? doc_doctor();

    return Patient::factory()->create([
        'branch_id' => $doctor->branch_id,
    ]);
}

test('document can be uploaded', function () {
    Storage::fake('medical');
    $doctor = doc_doctor();
    $patient = doc_patient($doctor);

    $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

    $service = app(DocumentService::class);
    $document = $service->upload($patient, $doctor, $file, 'lab_report');

    expect($document->patient_id)->toBe($patient->id)
        ->and($document->category)->toBe('lab_report')
        ->and($document->original_name)->toBe('test.pdf')
        ->and($document->mime_type)->toBe('application/pdf')
        ->and($document->size_bytes)->toBeGreaterThan(0)
        ->and($document->file_path)->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'medical_document.created')->first();
    expect($audit)->not->toBeNull();
    expect($audit->user_id)->toBe($doctor->id)
        ->and($audit->auditable_id)->toBe($document->id)
        ->and($audit->new_values['category'])->toBe('lab_report')
        ->and($audit->new_values['original_name'])->toBe('test.pdf');
});

test('document can be updated', function () {
    Storage::fake('medical');
    $doctor = doc_doctor();
    $patient = doc_patient($doctor);

    $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');
    $service = app(DocumentService::class);
    $document = $service->upload($patient, $doctor, $file, 'lab_report');

    $updated = $service->update($document, $doctor, ['category' => 'other']);

    expect($updated->category)->toBe('other');

    $audit = AuditLog::query()->where('action', 'medical_document.updated')->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_values['category'])->toBe('lab_report')
        ->and($audit->new_values['category'])->toBe('other');
});

test('document can be deleted', function () {
    Storage::fake('medical');
    $doctor = doc_doctor();
    $patient = doc_patient($doctor);

    $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');
    $service = app(DocumentService::class);
    $document = $service->upload($patient, $doctor, $file, 'lab_report');

    $result = $service->delete($document, $doctor);

    expect($result)->toBeTrue();
    expect(MedicalDocument::withTrashed()->find($document->id))->not->toBeNull();
    expect(MedicalDocument::find($document->id))->toBeNull();

    $audit = AuditLog::query()->where('action', 'medical_document.deleted')->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_values['file_path'])->toBe($document->file_path);
});
