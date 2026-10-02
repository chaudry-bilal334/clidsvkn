<?php

use App\Models\Patient;
use App\Models\User;
use App\Services\PatientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

pest()->use(RefreshDatabase::class);

test('saving recomputes identity columns when cnic changes', function () {
    $patient = Patient::factory()->create([
        'cnic' => '42101-1234567-1',
        'phone' => '+923001234567',
        'email' => 'old@example.com',
    ]);

    $oldCnicHash = $patient->cnic_hash;
    $oldPhoneNorm = $patient->phone_normalized;
    $oldEmailNorm = $patient->email_normalized;

    $patient->update(['cnic' => '35202-7654321-7']);
    $patient->refresh();

    expect($patient->cnic_hash)->not->toBe($oldCnicHash)
        ->and($patient->cnic_hash)->toBe(PatientService::cnicHash('35202-7654321-7'));
    // phone and email did not change so their normalized columns stay the same
    expect($patient->phone_normalized)->toBe($oldPhoneNorm)
        ->and($patient->email_normalized)->toBe($oldEmailNorm);
});

test('saving recomputes identity columns when phone changes', function () {
    $patient = Patient::factory()->create([
        'cnic' => '42101-1234567-1',
        'phone' => '+923001234567',
    ]);

    $oldPhoneNorm = $patient->phone_normalized;

    $patient->update(['phone' => '+923219876543']);
    $patient->refresh();

    expect($patient->phone_normalized)->not->toBe($oldPhoneNorm)
        ->and($patient->phone_normalized)->toBe(PatientService::normalizePhone('+923219876543'));
});

test('saving nulls out normalized columns when identity fields become null', function () {
    $patient = Patient::factory()->create([
        'cnic' => '42101-1234567-1',
        'phone' => '+923001234567',
        'email' => 'test@example.com',
    ]);

    expect($patient->cnic_hash)->not->toBeNull();
    expect($patient->phone_normalized)->not->toBeNull();
    expect($patient->email_normalized)->not->toBeNull();

    $patient->update(['cnic' => null, 'phone' => null, 'email' => null]);
    $patient->refresh();

    expect($patient->cnic_hash)->toBeNull()
        ->and($patient->phone_normalized)->toBeNull()
        ->and($patient->email_normalized)->toBeNull();
});

test('saving leaves a released normalized column alone when the source field is not dirty', function () {
    // Simulate a legacy row whose identity values exist but whose normalized
    // columns are missing.
    $patient = Patient::factory()->create([
        'cnic' => '42101-1234567-1',
        'phone' => '+923001234567',
    ]);

    // Manually null the normalized columns (bypassing the observer).
    Patient::withoutTimestamps(
        fn () => $patient->updateQuietly(['cnic_hash' => null, 'phone_normalized' => null]),
    );

    $patient->refresh();
    expect($patient->cnic_hash)->toBeNull();
    expect($patient->phone_normalized)->toBeNull();

    // Changing the source field recomputes the derived column.
    $patient->update(['cnic' => '42101-7654321-9']);
    $patient->refresh();

    expect($patient->cnic_hash)->toBe(PatientService::cnicHash('42101-7654321-9'));

    // An unrelated edit must NOT recompute it. Since `cnic_hash` is uniquely
    // indexed, self-healing a deliberately released index here would make an
    // ordinary address edit fail against a legacy duplicate's CNIC.
    DB::table('patients')->where('id', $patient->id)->update(['cnic_hash' => null]);

    $patient->refresh();
    $patient->update(['name' => 'Updated Name']);
    $patient->refresh();

    expect($patient->cnic_hash)->toBeNull();
});

test('merge two-owner refusal leaves both patients identity columns intact', function () {
    $survivor = Patient::factory()->create([
        'cnic' => '42101-1111111-1',
        'phone' => '+923001111111',
        'email' => 'survivor@example.com',
    ]);
    $duplicate = Patient::factory()->create([
        'cnic' => '35202-2222222-2',
        'phone' => '+923212222222',
        'email' => 'duplicate@example.com',
    ]);

    User::factory()->create(['role' => 'Receptionist', 'patient_id' => $survivor->id]);
    User::factory()->create(['role' => 'Receptionist', 'patient_id' => $duplicate->id]);

    // Snapshot the normalized columns before the refused merge.
    $survivorCnicHash = $survivor->fresh()->cnic_hash;
    $survivorPhoneNorm = $survivor->fresh()->phone_normalized;
    $survivorEmailNorm = $survivor->fresh()->email_normalized;
    $duplicateCnicHash = $duplicate->fresh()->cnic_hash;
    $duplicatePhoneNorm = $duplicate->fresh()->phone_normalized;
    $duplicateEmailNorm = $duplicate->fresh()->email_normalized;

    // The merge should throw because both patients have linked user accounts.
    try {
        app(PatientService::class)->mergePatients($survivor, $duplicate, User::factory()->create(['role' => 'Admin']), 'test');
        $this->fail('Expected HttpException was not thrown.');
    } catch (HttpException $e) {
        expect($e->getMessage())->toContain('Both patient records have linked user accounts');
    }

    // Neither patient should have been modified by the refused merge.
    expect($survivor->fresh()->cnic_hash)->toBe($survivorCnicHash)
        ->and($survivor->fresh()->phone_normalized)->toBe($survivorPhoneNorm)
        ->and($survivor->fresh()->email_normalized)->toBe($survivorEmailNorm)
        ->and($duplicate->fresh()->cnic_hash)->toBe($duplicateCnicHash)
        ->and($duplicate->fresh()->phone_normalized)->toBe($duplicatePhoneNorm)
        ->and($duplicate->fresh()->email_normalized)->toBe($duplicateEmailNorm);
});
