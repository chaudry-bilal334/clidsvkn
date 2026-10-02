<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VitalsService
{
    /**
     * Record vitals for an encounter.
     *
     * @param  array{
     *     blood_pressure: string|null,
     *     temperature: float|null,
     *     heart_rate: int|null,
     *     weight: float|null
     * }  $vitals
     */
    public function record(Encounter $encounter, User $recorder, array $vitals): Vital
    {
        return DB::transaction(function () use ($encounter, $recorder, $vitals): Vital {
            /** @var Encounter $locked */
            $locked = Encounter::query()->lockForUpdate()->findOrFail($encounter->id);

            $this->assertEditable($locked);

            $vital = $locked->vitals()->create($vitals + ['recorded_by' => $recorder->id]);

            AuditLog::record(
                $recorder,
                'encounter.vitals_recorded',
                $locked,
                newValues: $vital->only(['blood_pressure', 'temperature', 'heart_rate', 'weight']),
            );

            return $vital;
        });
    }

    /**
     * Update a vital sign entry.
     */
    public function update(Vital $vital, User $recorder, array $fields): Vital
    {
        $this->assertEditable($vital->encounter);

        $old = $vital->only(['blood_pressure', 'temperature', 'heart_rate', 'weight']);

        $vital->fill($fields);
        $vital->save();

        AuditLog::record(
            $recorder,
            'encounter.vitals_updated',
            $vital->encounter,
            oldValues: $old,
            newValues: $vital->only(['blood_pressure', 'temperature', 'heart_rate', 'weight']),
        );

        return $vital->fresh();
    }

    /**
     * Delete a vital sign entry.
     */
    public function delete(Vital $vital, User $recorder): bool
    {
        $this->assertEditable($vital->encounter);

        $old = $vital->only(['blood_pressure', 'temperature', 'heart_rate', 'weight']);

        $vital->delete();

        AuditLog::record(
            $recorder,
            'encounter.vitals_deleted',
            $vital->encounter,
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
