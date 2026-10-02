<?php

use App\Models\Allergen;
use App\Models\Branch;
use App\Models\Consent;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\User;
use App\Services\PatientService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

pest()->use(RefreshDatabase::class);

function merge_admin(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

test('the database enforces one patient per CNIC', function () {
    // The guarantee is enforced in the schema, not just in a form validator.
    $cnicUnique = collect(Schema::getIndexes('patients'))
        ->contains(fn (array $index): bool => $index['name'] === 'patients_cnic_hash_unique' && (bool) $index['unique']);

    expect($cnicUnique)->toBeTrue();

    $branch = Branch::factory()->create();

    Patient::factory()->create(['cnic' => '42201-1234567-1', 'branch_id' => $branch->id]);

    // A second registration claiming the same national ID must not commit.
    expect(fn () => Patient::factory()->create(['cnic' => '42201-1234567-1', 'branch_id' => $branch->id]))
        ->toThrow(QueryException::class);

    expect(Patient::count())->toBe(1);
});

test('a duplicate CNIC is rejected with a readable 422 rather than a database error', function () {
    $user = merge_admin();
    $branch = Branch::factory()->create();

    Patient::factory()->create(['cnic' => '42201-1234567-1', 'branch_id' => $branch->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/patients', [
            'name' => 'Same Identity',
            'cnic' => '42201-1234567-1',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.cnic.0', 'A patient with this CNIC already exists.');

    expect(Patient::count())->toBe(1);
});

test('CNIC uniqueness is checked on the normalized form, not the raw string', function () {
    $user = merge_admin();
    $branch = Branch::factory()->create();

    Patient::factory()->create(['cnic' => '42201-1234567-1', 'branch_id' => $branch->id]);

    // Spacing and case must not create a way around the check.
    $this->actingAs($user)
        ->postJson('/api/v1/patients', [
            'name' => 'Reformatted Identity',
            'cnic' => ' 42201 1234567 1 ',
        ])
        ->assertUnprocessable();

    expect(Patient::count())->toBe(1);
});

test('patients without a CNIC are unaffected by the unique index', function () {
    $branch = Branch::factory()->create();

    Patient::factory()->count(3)->create(['cnic' => null, 'branch_id' => $branch->id]);

    // SQL unique semantics already allow any number of NULLs.
    expect(Patient::whereNull('cnic_hash')->count())->toBe(3);
});

test('a patient may keep their own CNIC when edited', function () {
    $user = merge_admin();
    $patient = Patient::factory()->create(['cnic' => '42201-1234567-1']);

    $this->actingAs($user)
        ->putJson("/api/v1/patients/{$patient->id}", [
            'name' => $patient->name,
            'cnic' => '42201-1234567-1',
        ])
        ->assertOk();

    expect($patient->refresh()->cnic)->toBe('42201-1234567-1');
});

test('an unrelated edit to a patient does not disturb their identity index', function () {
    $patient = Patient::factory()->create(['cnic' => '42201-1234567-1', 'phone' => '03001234567']);
    $before = $patient->cnic_hash;

    $patient->update(['address' => 'Somewhere else']);

    expect($patient->refresh()->cnic_hash)->toBe($before);
});

test('merging two patients carries the allergy records across', function () {
    $survivor = Patient::factory()->create();
    $duplicate = Patient::factory()->create();

    $penicillin = Allergen::factory()->create(['name' => 'Penicillin']);

    PatientAllergy::factory()->create([
        'patient_id' => $survivor->id,
        'allergen_id' => $penicillin->id,
        'severity' => 'mild',
    ]);

    // A recorded severe allergy that must survive the merge: if this is lost
    // the CDSS allergy check silently stops firing for this patient.
    $sulfa = Allergen::factory()->create(['name' => 'Sulfonamides']);
    PatientAllergy::factory()->create([
        'patient_id' => $duplicate->id,
        'allergen_id' => $sulfa->id,
        'severity' => 'life_threatening',
        'reaction' => 'Anaphylaxis',
        'first_reaction_at' => '2019-04-02',
    ]);

    app(PatientService::class)->mergePatients($survivor, $duplicate, merge_admin(), 'Same person, double registered');

    expect(PatientAllergy::where('patient_id', $survivor->id)->count())->toBe(2)
        ->and(PatientAllergy::where('patient_id', $survivor->id)
            ->where('allergen_id', $sulfa->id)
            ->where('severity', 'life_threatening')
            ->exists())->toBeTrue()
        ->and(Patient::whereKey($duplicate->id)->exists())->toBeFalse();
});

test('merging collapses a duplicated allergen conservatively, keeping the more severe record', function () {
    $survivor = Patient::factory()->create();
    $duplicate = Patient::factory()->create();

    $penicillin = Allergen::factory()->create(['name' => 'Penicillin']);

    PatientAllergy::factory()->create([
        'patient_id' => $survivor->id,
        'allergen_id' => $penicillin->id,
        'severity' => 'moderate',
        // The survivor has no reaction description of its own, so the
        // duplicate's is carried over rather than left blank.
        'reaction' => null,
        'first_reaction_at' => '2021-06-01',
    ]);

    PatientAllergy::factory()->create([
        'patient_id' => $duplicate->id,
        'allergen_id' => $penicillin->id,
        'severity' => 'life_threatening',
        'reaction' => 'Anaphylaxis',
        'first_reaction_at' => '2015-02-03',
    ]);

    app(PatientService::class)->mergePatients($survivor, $duplicate, merge_admin());

    $allergies = PatientAllergy::where('patient_id', $survivor->id)->get();

    expect($allergies)->toHaveCount(1);

    $merged = $allergies->first();

    // Understating an allergy is the one error the CDSS cannot recover from, so
    // severity escalates, the earliest known reaction is retained, and a
    // reaction description the survivor lacked is filled in.
    expect($merged->severity)->toBe('life_threatening')
        ->and($merged->reaction)->toBe('Anaphylaxis')
        ->and($merged->first_reaction_at)->toBe('2015-02-03');
});

test('merging carries the consent history across', function () {
    $survivor = Patient::factory()->create();
    $duplicate = Patient::factory()->create();
    $user = User::factory()->create(['role' => 'Patient']);

    Consent::factory()->create([
        'patient_id' => $survivor->id,
        'user_id' => $user->id,
        'consent_type' => 'treatment',
    ]);

    // A withdrawn consent is compliance evidence and must not vanish.
    Consent::factory()->create([
        'patient_id' => $duplicate->id,
        'user_id' => $user->id,
        'consent_type' => 'communications',
        'is_active' => false,
        'withdrawn_by' => 'patient',
        'withdrawn_at' => now()->subMonth(),
    ]);

    app(PatientService::class)->mergePatients($survivor, $duplicate, merge_admin());

    expect(Consent::where('patient_id', $survivor->id)->count())->toBe(2)
        ->and(Consent::where('patient_id', $survivor->id)
            ->where('consent_type', 'communications')
            ->where('is_active', false)
            ->exists())->toBeTrue();
});

test('a patient with a recorded allergy or consent can never be deleted', function () {
    $admin = merge_admin();

    $withAllergy = Patient::factory()->create();
    PatientAllergy::factory()->create(['patient_id' => $withAllergy->id]);

    $withConsent = Patient::factory()->create();
    Consent::factory()->create(['patient_id' => $withConsent->id]);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/patients/{$withAllergy->id}")
        ->assertUnprocessable();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/patients/{$withConsent->id}")
        ->assertUnprocessable();

    expect(Patient::whereKey($withAllergy->id)->exists())->toBeTrue()
        ->and(Patient::whereKey($withConsent->id)->exists())->toBeTrue();
});
