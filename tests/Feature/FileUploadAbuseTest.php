<?php

use App\Models\MedicalDocument;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

pest()->use(RefreshDatabase::class);

const UPLOAD_CATEGORY = 'lab_report';

test('staff upload rejects a document larger than five megabytes', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $patient = Patient::factory()->create();

    $this->actingAs($doctor)
        ->postJson('/api/v1/patients/'.$patient->id.'/documents', [
            'category' => UPLOAD_CATEGORY,
            'file' => UploadedFile::fake()->create('big.pdf', 8 * 1024),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(MedicalDocument::count())->toBe(0);
});

test('content sniffing rejects a php payload renamed to pdf', function () {
    // GUARD CLOSED (was a characterization test of the File::types() gap): the
    // payload's magic bytes no longer match the allowed content types, so the
    // upload is refused even though the extension looks harmless.
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $patient = Patient::factory()->create();

    $this->actingAs($doctor)
        ->postJson('/api/v1/patients/'.$patient->id.'/documents', [
            'category' => UPLOAD_CATEGORY,
            'file' => UploadedFile::fake()->createWithContent('report.pdf', '<?php echo "owned"; ?>'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(MedicalDocument::count())->toBe(0);
});

test('content sniffing rejects a plain text file renamed to pdf', function () {
    // Same closed guard: the plain-text bytes are not an allowed document MIME
    // type for the pdf extension.
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $patient = Patient::factory()->create();

    $this->actingAs($doctor)
        ->postJson('/api/v1/patients/'.$patient->id.'/documents', [
            'category' => UPLOAD_CATEGORY,
            'file' => UploadedFile::fake()->createWithContent('report.pdf', 'plain text, definitely not a real pdf'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(MedicalDocument::count())->toBe(0);
});

test('staff upload rejects a php script file regardless of its mime claim', function () {
    $doctor = User::factory()->create(['role' => 'Doctor']);
    $patient = Patient::factory()->create();

    $this->actingAs($doctor)
        ->postJson('/api/v1/patients/'.$patient->id.'/documents', [
            'category' => UPLOAD_CATEGORY,
            'file' => UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]); ?>'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(MedicalDocument::count())->toBe(0);
});

test('staff upload sanitizes a path traversal filename so the stored path cannot escape the medical disk', function () {
    Storage::fake('medical');

    $doctor = User::factory()->create(['role' => 'Doctor']);
    $patient = Patient::factory()->create();

    $response = $this->actingAs($doctor)
        ->postJson('/api/v1/patients/'.$patient->id.'/documents', [
            'category' => UPLOAD_CATEGORY,
            'file' => UploadedFile::fake()->createWithContent('../../etc/passwd.pdf', '%PDF-1.4 '.str_repeat('x', 2048)),
        ])
        ->assertCreated();

    $document = MedicalDocument::findOrFail($response->json('data.id'));

    expect($document->file_path)
        ->not->toContain('..')
        ->toMatch('/^[a-z0-9_-]+\.[a-z]{2,5}$/');

    expect(Storage::disk('medical')->exists($document->file_path))->toBeTrue();

    // The client-supplied filename is normalized to its basename at the
    // UploadedFile boundary and the store path is slugged; the recorded
    // original_name is display-only and never used as a path.
    expect($document->original_name)->toBe('passwd.pdf');
});

test('the portal upload path applies the same file-size and type guards', function () {
    $patient = Patient::factory()->create();
    $user = User::factory()->create(['role' => 'Patient', 'patient_id' => $patient->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/portal/documents', [
            'category' => UPLOAD_CATEGORY,
            'file' => UploadedFile::fake()->create('portrait.pdf', 8 * 1024),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    $this->actingAs($user)
        ->postJson('/api/v1/portal/documents', [
            'category' => UPLOAD_CATEGORY,
            'file' => UploadedFile::fake()->createWithContent('note.exe', 'MZ this is not an image'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(MedicalDocument::count())->toBe(0);
});

test('a valid upload still succeeds after the abuse vectors', function () {
    Storage::fake('medical');

    $doctor = User::factory()->create(['role' => 'Doctor']);
    $patient = Patient::factory()->create();

    $this->actingAs($doctor)
        ->postJson('/api/v1/patients/'.$patient->id.'/documents', [
            'category' => UPLOAD_CATEGORY,
            'file' => UploadedFile::fake()->createWithContent('real-report.pdf', '%PDF-1.4 fake but well-formed pdf bytes'),
        ])
        ->assertCreated();

    expect(MedicalDocument::where('patient_id', $patient->id)->count())->toBe(1);
});

test('a genuine image upload still passes content sniffing', function () {
    Storage::fake('medical');

    $doctor = User::factory()->create(['role' => 'Doctor']);
    $patient = Patient::factory()->create();

    // A real 1x1 PNG: magic bytes detect as image/png, which the sniff rule
    // accepts for the png extension.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    $this->actingAs($doctor)
        ->postJson('/api/v1/patients/'.$patient->id.'/documents', [
            'category' => UPLOAD_CATEGORY,
            'file' => UploadedFile::fake()->createWithContent('scan-1.png', $png),
        ])
        ->assertCreated();

    expect(MedicalDocument::where('patient_id', $patient->id)->count())->toBe(1);
});
