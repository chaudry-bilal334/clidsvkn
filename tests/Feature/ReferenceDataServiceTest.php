<?php

use App\Models\Allergen;
use App\Models\AuditLog;
use App\Models\DosageRule;
use App\Models\DrugInteractionRule;
use App\Models\User;
use App\Services\ReferenceDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

pest()->use(RefreshDatabase::class);

function ref_doctor(): User
{
    return User::factory()->create(['role' => 'Doctor']);
}

test('pending reference data can be listed', function () {
    $allergen = Allergen::factory()->create(['name' => 'Penicillin', 'is_approved' => false]);
    $interaction = DrugInteractionRule::factory()->create([
        'medicine_a' => 'Warfarin',
        'medicine_b' => 'Ibuprofen',
        'is_approved' => false,
    ]);
    $dosage = DosageRule::factory()->create(['medicine_name' => 'Warfarin', 'is_approved' => false]);

    $service = app(ReferenceDataService::class);
    $pending = $service->pending();

    expect($pending['allergens'])->toHaveCount(1)
        ->and($pending['drug_interactions'])->toHaveCount(1)
        ->and($pending['dosage_rules'])->toHaveCount(1);
});

test('allergen can be approved', function () {
    $allergen = Allergen::factory()->create(['name' => 'Penicillin', 'is_approved' => false]);

    $service = app(ReferenceDataService::class);
    $doctor = User::factory()->create(['role' => 'Doctor']);

    $approved = $service->approve('allergens', $allergen->id, $doctor);

    expect($approved->is_approved)->toBeTrue()
        ->and($approved->approved_by)->not->toBeNull()
        ->and($approved->approved_at)->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'reference_data.approved')->first();
    expect($audit)->not->toBeNull();
    expect($audit->new_values['type'])->toBe('allergens')
        ->and($audit->new_values['id'])->toBe($allergen->id)
        ->and($audit->new_values['is_approved'])->toBeTrue();
});

test('allergen can be rejected', function () {
    $allergen = Allergen::factory()->create(['name' => 'Penicillin', 'is_approved' => true]);

    $service = app(ReferenceDataService::class);
    $doctor = User::factory()->create(['role' => 'Doctor']);

    $rejected = $service->reject('allergens', $allergen->id, $doctor);

    expect($rejected->is_approved)->toBeFalse()
        ->and($rejected->approved_by)->toBeNull()
        ->and($rejected->approved_at)->toBeNull();

    $audit = AuditLog::query()->where('action', 'reference_data.rejected')->first();
    expect($audit)->not->toBeNull();
    expect($audit->new_values['type'])->toBe('allergens')
        ->and($audit->new_values['is_approved'])->toBeFalse();
});

test('drug interaction can be approved', function () {
    $interaction = DrugInteractionRule::factory()->create([
        'medicine_a' => 'Warfarin',
        'medicine_b' => 'Ibuprofen',
        'is_approved' => false,
    ]);

    $service = app(ReferenceDataService::class);
    $doctor = User::factory()->create(['role' => 'Doctor']);

    $approved = $service->approve('drug_interactions', $interaction->id, $doctor);

    expect($approved->is_approved)->toBeTrue()
        ->and($approved->approved_by)->not->toBeNull()
        ->and($approved->approved_at)->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'reference_data.approved')->first();
    expect($audit)->not->toBeNull();
    expect($audit->new_values['type'])->toBe('drug_interactions')
        ->and($audit->new_values['is_approved'])->toBeTrue();
});

test('dosage rule can be approved', function () {
    $dosage = DosageRule::factory()->create([
        'medicine_name' => 'Warfarin',
        'is_approved' => false,
    ]);

    $service = app(ReferenceDataService::class);
    $doctor = User::factory()->create(['role' => 'Doctor']);

    $approved = $service->approve('dosage_rules', $dosage->id, $doctor);

    expect($approved->is_approved)->toBeTrue()
        ->and($approved->approved_by)->not->toBeNull()
        ->and($approved->approved_at)->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'reference_data.approved')->first();
    expect($audit)->not->toBeNull();
    expect($audit->new_values['type'])->toBe('dosage_rules')
        ->and($audit->new_values['is_approved'])->toBeTrue();
});

test('invalid type throws validation exception', function () {
    $service = app(ReferenceDataService::class);
    $doctor = User::factory()->create(['role' => 'Doctor']);

    expect(fn () => $service->approve('invalid_type', 1, $doctor))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->reject('invalid_type', 1, $doctor))
        ->toThrow(ValidationException::class);
});
