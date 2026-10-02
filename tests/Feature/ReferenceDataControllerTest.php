<?php

use App\Models\Allergen;
use App\Models\AuditLog;
use App\Models\DosageRule;
use App\Models\DrugInteractionRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function rda_admin(): User
{
    return User::factory()->create(['role' => 'Admin']);
}

test('pending reference data is listed per type', function () {
    $admin = rda_admin();

    Allergen::factory()->create(['name' => 'Penicillin', 'is_approved' => false]);
    Allergen::factory()->create(['name' => 'Latex', 'is_approved' => true]);
    DrugInteractionRule::factory()->create(['medicine_a' => 'Warfarin', 'medicine_b' => 'Aspirin', 'is_approved' => false]);
    DosageRule::factory()->create(['medicine_name' => 'Paracetamol', 'is_approved' => false]);

    $this->actingAs($admin)
        ->getJson('/api/v1/reference-data/pending?type=allergens')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Penicillin');

    $this->actingAs($admin)
        ->getJson('/api/v1/reference-data/pending?type=drug_interactions')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('an invalid reference data type is rejected', function () {
    $this->actingAs(rda_admin())
        ->getJson('/api/v1/reference-data/pending?type=unicorns')
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('reference data can be approved and is audited', function () {
    $admin = rda_admin();
    $allergen = Allergen::factory()->create(['name' => 'Penicillin', 'is_approved' => false]);

    $this->actingAs($admin)
        ->postJson("/api/v1/reference-data/approve/allergens/{$allergen->id}")
        ->assertOk()
        ->assertJsonPath('data.is_approved', true);

    $allergen->refresh();
    expect($allergen->is_approved)->toBeTrue()
        ->and($allergen->approved_by)->toBe($admin->id)
        ->and($allergen->approved_at)->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'reference_data.approved')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->auditable_id)->toBe($allergen->id);
});

test('approved reference data can be returned to pending', function () {
    $admin = rda_admin();
    $rule = DrugInteractionRule::factory()->create(['is_approved' => true, 'approved_by' => $admin->id, 'approved_at' => now()]);

    $this->actingAs($admin)
        ->postJson("/api/v1/reference-data/reject/drug_interactions/{$rule->id}")
        ->assertOk()
        ->assertJsonPath('data.is_approved', false);

    $rule->refresh();
    expect($rule->is_approved)->toBeFalse()
        ->and($rule->approved_by)->toBeNull()
        ->and($rule->approved_at)->toBeNull();

    expect(AuditLog::query()->where('action', 'reference_data.rejected')->first())->not->toBeNull();
});

test('reference data approval requires the approve permission', function () {
    $allergen = Allergen::factory()->create(['is_approved' => false]);
    $doctor = User::factory()->create(['role' => 'Doctor']);

    $this->actingAs($doctor)
        ->postJson("/api/v1/reference-data/approve/allergens/{$allergen->id}")
        ->assertStatus(403);

    expect($allergen->fresh()->is_approved)->toBeFalse();
});

test('approving a missing reference data record returns 404', function () {
    $this->actingAs(rda_admin())
        ->postJson('/api/v1/reference-data/approve/allergens/999999')
        ->assertNotFound();
});
