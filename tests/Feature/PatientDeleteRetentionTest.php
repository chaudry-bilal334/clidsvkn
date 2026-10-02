<?php

use App\Models\Consent;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Indefinite-retention guard (docs/UNRESOLVED_DECISIONS.md Retention row):
 * `DELETE /api/v1/patients/{id}` must refuse to delete a patient with any
 * clinical history (422) while still allowing deletion of genuinely empty
 * patients (mistaken/duplicate registrations), and must leave the
 * duplicate-merge flow untouched.
 */
pest()->use(RefreshDatabase::class);

function delete_retention_admin(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

test('deleting a patient with clinical records is blocked with a 422 and the record persists', function () {
    $admin = delete_retention_admin();
    $patient = Patient::factory()->create();
    Encounter::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/patients/{$patient->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete patient with existing clinical records.');

    expect(Patient::find($patient->id))->not->toBeNull();

    // The clinical record itself is untouched.
    expect(DB::table('encounters')->where('patient_id', $patient->id)->count())->toBe(1);
});

test('deleting a patient with zero clinical records succeeds', function () {
    $admin = delete_retention_admin();
    $patient = Patient::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/patients/{$patient->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Patient deleted.');

    expect(Patient::find($patient->id))->toBeNull();
});

test('the guard also covers tables the duplicate-merge path does not reparent', function () {
    $admin = delete_retention_admin();

    // consents and patient_allergies carry patient_id but are NOT in the
    // merge CHILD_TABLES list — they must still block deletion.
    $consented = Patient::factory()->create();
    Consent::factory()->create(['patient_id' => $consented->id]);

    $allergic = Patient::factory()->create();
    PatientAllergy::factory()->create(['patient_id' => $allergic->id]);

    foreach ([$consented, $allergic] as $patient) {
        $this->actingAs($admin)
            ->deleteJson("/api/v1/patients/{$patient->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete patient with existing clinical records.');
    }

    expect(Patient::find($consented->id))->not->toBeNull();
    expect(Patient::find($allergic->id))->not->toBeNull();
});

test('the duplicate-merge flow still works and the guard applies to the merged survivor', function () {
    $admin = delete_retention_admin();

    $survivor = Patient::factory()->create();
    $duplicate = Patient::factory()->create();

    // The duplicate carries clinical history (an encounter) that the merge
    // must reparent before deleting the duplicate row.
    Encounter::factory()->create(['patient_id' => $duplicate->id]);

    $this->actingAs($admin)
        ->postJson('/api/v1/patients/merge', [
            'survivor_id' => $survivor->id,
            'duplicate_id' => $duplicate->id,
            'reason' => 'test retention guard regression',
        ])
        ->assertOk();

    // Duplicate is gone, its clinical record now belongs to the survivor.
    expect(Patient::find($duplicate->id))->toBeNull();
    expect(DB::table('encounters')->where('patient_id', $survivor->id)->count())->toBe(1);
    expect(DB::table('encounters')->where('patient_id', $duplicate->id)->count())->toBe(0);

    // The survivor now has clinical history of its own — deleting it is blocked.
    $this->actingAs($admin)
        ->deleteJson("/api/v1/patients/{$survivor->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete patient with existing clinical records.');
});
