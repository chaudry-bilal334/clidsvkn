<?php

use App\Models\MedicalDocument;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

pest()->use(RefreshDatabase::class);

function staffDoctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

function linkedPatient(User $user): Patient
{
    $patient = Patient::factory()->create();

    $user->update(['patient_id' => $patient->id]);

    return $patient;
}

function uploadFile(string $name = 'report.pdf', int $kilobytes = 64, ?string $mime = null): UploadedFile
{
    // Realistic bytes for allowed document extensions so the content-sniffing
    // guard accepts legitimate uploads; everything else stays content-free and
    // is rejected by the extension/MIME rules before sniffing.
    $isAllowedType = preg_match('/\.(pdf|jpe?g|png|webp)$/i', $name) === 1;

    $content = $isAllowedType
        ? '%PDF-1.4 '.str_repeat('0', max(1, $kilobytes) * 1024)
        : str_repeat('0', max(1, $kilobytes) * 1024);

    return UploadedFile::fake()->createWithContent($name, $content, $mime);
}

test('staff can list a patient document backlog', function () {
    Storage::fake('medical');
    $doctor = staffDoctor();
    $patient = Patient::factory()->create();

    MedicalDocument::factory()->count(2)->create(['patient_id' => $patient->id]);
    MedicalDocument::factory()->create();

    $this->actingAs($doctor)
        ->getJson('/api/v1/patients/'.$patient->id.'/documents')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('staff can upload a medical document for a patient', function () {
    Storage::fake('medical');
    $doctor = staffDoctor();
    $patient = Patient::factory()->create();

    $this->actingAs($doctor)
        ->postJson('/api/v1/patients/'.$patient->id.'/documents', [
            'category' => 'lab_report',
            'file' => uploadFile(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.category', 'lab_report')
        ->assertJsonPath('data.original_name', 'report.pdf');

    $document = MedicalDocument::query()->firstOrFail();
    expect($document->patient_id)->toBe($patient->id);
    expect($document->uploaded_by)->toBe($doctor->id);
    Storage::disk('medical')->assertExists($document->file_path);
});

test('upload rejects an invalid category or file type', function () {
    Storage::fake('medical');
    $doctor = staffDoctor();
    $patient = Patient::factory()->create();

    $this->actingAs($doctor)
        ->postJson('/api/v1/patients/'.$patient->id.'/documents', [
            'category' => 'laboratory',
            'file' => uploadFile(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category');

    $this->actingAs($doctor)
        ->postJson('/api/v1/patients/'.$patient->id.'/documents', [
            'category' => 'other',
            'file' => uploadFile('scan.exe', 64),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

test('a patient can view and upload only their own documents via the portal', function () {
    Storage::fake('medical');
    $user = User::factory()->create(['role' => 'Patient']);
    $patient = linkedPatient($user);
    $other = Patient::factory()->create();

    MedicalDocument::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/portal/documents')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($user)
        ->postJson('/api/v1/portal/documents', [
            'category' => 'insurance_card',
            'file' => uploadFile('card.pdf'),
        ])
        ->assertCreated()
        ->assertJsonPath('data.category', 'insurance_card');

    $this->actingAs($user)
        ->getJson('/api/v1/patients/'.$other->id.'/documents')
        ->assertForbidden();
});

test('patients can download their own documents but not another patients', function () {
    Storage::fake('medical');
    $user = User::factory()->create(['role' => 'Patient']);
    $patient = linkedPatient($user);
    $other = Patient::factory()->create();

    $own = MedicalDocument::factory()->create([
        'patient_id' => $patient->id,
        'original_name' => 'own.pdf',
    ]);
    Storage::disk('medical')->put($own->file_path, 'pdf-content');

    $foreign = MedicalDocument::factory()->create(['patient_id' => $other->id]);
    Storage::disk('medical')->put($foreign->file_path, 'pdf-content');

    $this->actingAs($user)
        ->get('/api/v1/portal/documents/'.$own->id.'/download')
        ->assertOk()
        ->assertDownload('own.pdf');

    $this->actingAs($user)
        ->getJson('/api/v1/portal/documents/'.$foreign->id.'/download')
        ->assertForbidden();
});

test('staff can download a patient document that exists on the private disk', function () {
    Storage::fake('medical');
    $doctor = staffDoctor();
    $patient = Patient::factory()->create();

    $document = MedicalDocument::factory()->create([
        'patient_id' => $patient->id,
        'original_name' => 'consent.pdf',
    ]);
    Storage::disk('medical')->put($document->file_path, 'pdf-content');

    $this->actingAs($doctor)
        ->get('/api/v1/documents/'.$document->id.'/download')
        ->assertOk()
        ->assertDownload('consent.pdf');
});
