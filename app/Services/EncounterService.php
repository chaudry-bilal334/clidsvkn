<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EncounterService
{
    public function openEncounter(int $patientId, User $doctor, ?int $appointmentId = null, ?string $notes = null): Encounter
    {
        $encounter = Encounter::create([
            'patient_id' => $patientId,
            'doctor_id' => $doctor->id,
            'appointment_id' => $appointmentId,
            'status' => Encounter::STATUS_DRAFT,
            'notes' => $notes,
        ]);

        AuditLog::record(
            $doctor,
            'encounter.created',
            $encounter,
            newValues: [
                'patient_id' => $patientId,
                'doctor_id' => $doctor->id,
                'appointment_id' => $appointmentId,
                'status' => Encounter::STATUS_DRAFT,
            ],
        );

        return $encounter;
    }

    /**
     * Lock a draft encounter as the final clinical record. A finalized
     * encounter can no longer accept vitals or diagnoses; later corrections
     * must go through void/amend so the original is preserved.
     */
    public function finalize(Encounter $encounter, User $actor): Encounter
    {
        return DB::transaction(function () use ($encounter, $actor): Encounter {
            /** @var Encounter $locked */
            $locked = Encounter::query()->lockForUpdate()->findOrFail($encounter->id);

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages([
                    'encounter' => ['Only a draft encounter can be finalized.'],
                ]);
            }

            $previous = $locked->status;

            $locked->update([
                'status' => Encounter::STATUS_FINALIZED,
                'finalized_at' => now(),
                'finalized_by' => $actor->id,
            ]);

            AuditLog::record(
                $actor,
                'encounter.finalized',
                $locked,
                oldValues: ['status' => $previous],
                newValues: [
                    'status' => Encounter::STATUS_FINALIZED,
                    'finalized_by' => $actor->id,
                ],
            );

            return $locked;
        });
    }

    /**
     * Void a clinical record. The row and its clinical content are preserved;
     * the reason and actor are recorded for audit.
     */
    public function void(Encounter $encounter, User $actor, string $reason): Encounter
    {
        return DB::transaction(function () use ($encounter, $actor, $reason): Encounter {
            /** @var Encounter $locked */
            $locked = Encounter::query()->lockForUpdate()->findOrFail($encounter->id);

            if ($locked->isVoided()) {
                throw ValidationException::withMessages([
                    'encounter' => ['This encounter has already been voided.'],
                ]);
            }

            $previous = $locked->status;

            $locked->update([
                'status' => Encounter::STATUS_VOIDED,
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ]);

            AuditLog::record(
                $actor,
                'encounter.voided',
                $locked,
                oldValues: ['status' => $previous],
                newValues: ['status' => Encounter::STATUS_VOIDED, 'void_reason' => $reason],
            );

            return $locked;
        });
    }

    /**
     * Start an amendment as a fresh draft linked to the original finalized or
     * voided encounter. The original record is never mutated.
     */
    public function amend(Encounter $encounter, User $actor): Encounter
    {
        return DB::transaction(function () use ($encounter, $actor): Encounter {
            /** @var Encounter $locked */
            $locked = Encounter::query()->lockForUpdate()->findOrFail($encounter->id);

            if ($locked->isDraft()) {
                throw ValidationException::withMessages([
                    'encounter' => ['A draft encounter is edited directly; only finalized or voided records are amended.'],
                ]);
            }

            $amendment = Encounter::create([
                'patient_id' => $locked->patient_id,
                'doctor_id' => $actor->id,
                'appointment_id' => $locked->appointment_id,
                'status' => Encounter::STATUS_DRAFT,
                'notes' => $locked->notes,
                'amends_encounter_id' => $locked->id,
            ]);

            AuditLog::record(
                $actor,
                'encounter.amended',
                $amendment,
                oldValues: ['amends_encounter_id' => $locked->id],
                newValues: ['patient_id' => $locked->patient_id, 'amends_encounter_id' => $locked->id],
            );

            return $amendment;
        });
    }

    /**
     * @param  array{blood_pressure?: string|null, temperature?: float|null, heart_rate?: int|null, weight?: float|null}  $vitals
     */
    public function recordVitals(Encounter $encounter, User $recorder, array $vitals): Vital
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

    public function recordDiagnosis(Encounter $encounter, User $recorder, string $icdCode, ?string $description = null): Diagnosis
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

    private function assertEditable(Encounter $encounter): void
    {
        if ($encounter->isEditable()) {
            return;
        }

        throw ValidationException::withMessages([
            'encounter' => ['This encounter is finalized and cannot be edited. Use void/amend to make a correction.'],
        ]);
    }
}
