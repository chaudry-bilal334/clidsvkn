<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiagnosisService
{
    /**
     * Record a diagnosis for an encounter.
     */
    public function record(Encounter $encounter, User $recorder, string $icdCode, ?string $description = null): Diagnosis
    {
        return DB::transaction(function () use ($encounter, $recorder, $icdCode, $description): Diagnosis {
            /** @var Encounter $locked */
            $locked = Encounter::query()->lockForUpdate()->findOrFail($encounter->id);

            $this->assertEditable($locked);

            $diagnosis = $locked->diagnoses()->create([
                'icd_code' => $icdCode,
                'description' => $description,
                'recorded_by' => $recorder->id,
            ]);

            AuditLog::record(
                $recorder,
                'encounter.diagnosis_recorded',
                $locked,
                newValues: ['icd_code' => $icdCode, 'description' => $description],
            );

            return $diagnosis;
        });
    }

    /**
     * Update a diagnosis entry.
     */
    public function update(Diagnosis $diagnosis, User $recorder, string $icdCode, ?string $description = null): Diagnosis
    {
        $this->assertEditable($diagnosis->encounter);

        $old = $diagnosis->only(['icd_code', 'description']);

        $diagnosis->update([
            'icd_code' => $icdCode,
            'description' => $description,
        ]);

        AuditLog::record(
            $recorder,
            'encounter.diagnosis_updated',
            $diagnosis->encounter,
            oldValues: $old,
            newValues: ['icd_code' => $icdCode, 'description' => $description],
        );

        return $diagnosis->fresh();
    }

    /**
     * Delete a diagnosis entry.
     */
    public function delete(Diagnosis $diagnosis, User $recorder): bool
    {
        $this->assertEditable($diagnosis->encounter);

        $old = $diagnosis->only(['icd_code', 'description']);

        $diagnosis->delete();

        AuditLog::record(
            $recorder,
            'encounter.diagnosis_deleted',
            $diagnosis->encounter,
            oldValues: $old,
            newValues: ['deleted_at' => now()->toIso8601String()],
        );

        return true;
    }

    private function assertEditable(Encounter $encounter): void
    {
        if (! $encounter->isEditable()) {
            throw ValidationException::withMessages([
                'encounter' => ['This encounter is finalized and cannot be edited.'],
            ]);
        }
    }
}
