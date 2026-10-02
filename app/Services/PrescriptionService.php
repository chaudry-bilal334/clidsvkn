<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrescriptionService
{
    /**
     * Create a new prescription in the initial `pending` state.
     *
     * @param  array{
     *     patient_id: int,
     *     patient_name: string,
     *     doctor_id: int,
     *     doctor_name: string,
     *     appointment_id: int|null,
     *     notes: string|null,
     *     items: array<int, array{medicine_name: string, dosage?: string|null, frequency?: string|null, duration?: string|null, instructions?: string|null, quantity?: int|null}>
     * }  $fields
     *
     * @throws ValidationException on duplicate prescription number race
     */
    public function create(array $fields, User $actor): Prescription
    {
        return DB::transaction(function () use ($fields, $actor): Prescription {
            $prescription = Prescription::create([
                'prescription_number' => 'TMP-'.uniqid(),
                'patient_id' => $fields['patient_id'],
                'patient_name' => $fields['patient_name'],
                'doctor_id' => $fields['doctor_id'],
                'doctor_name' => $fields['doctor_name'],
                'appointment_id' => $fields['appointment_id'],
                'status' => Prescription::STATUS_PENDING,
                'notes' => $fields['notes'],
                'issued_at' => now(),
            ]);

            $prescription->prescription_number = sprintf('RX-%05d', $prescription->id);
            $prescription->save();

            foreach ($fields['items'] as $item) {
                $prescription->items()->create($item);
            }

            AuditLog::record(
                $actor,
                'prescription.created',
                $prescription,
                newValues: [
                    'patient_id' => $fields['patient_id'],
                    'doctor_id' => $fields['doctor_id'],
                    'items' => $fields['items'],
                ],
            );

            return $prescription;
        });
    }

    /**
     * Lock a prescription as the final signed record. A finalized
     * prescription can no longer be edited; corrections use void/amend so the
     * original is preserved.
     */
    public function finalize(Prescription $prescription, User $actor): Prescription
    {
        return DB::transaction(function () use ($prescription, $actor): Prescription {
            /** @var Prescription $locked */
            $locked = Prescription::query()->lockForUpdate()->findOrFail($prescription->id);

            if (! in_array($locked->status, [Prescription::STATUS_PENDING, Prescription::STATUS_IN_PROGRESS], true)) {
                throw ValidationException::withMessages([
                    'prescription' => ['Only a pending prescription can be finalized.'],
                ]);
            }

            $locked->update([
                'status' => Prescription::STATUS_FINALIZED,
                'finalized_at' => now(),
                'finalized_by' => $actor->id,
            ]);

            AuditLog::record(
                $actor,
                'prescription.finalized',
                $locked,
                newValues: ['finalized_by' => $actor->id, 'finalized_at' => $locked->finalized_at?->toIso8601String()],
            );

            return $locked;
        });
    }

    /**
     * Void a prescription. The row and its items are preserved; the reason and
     * actor are recorded for audit. Dispensed prescriptions are already
     * fulfilled and cannot be voided.
     */
    public function void(Prescription $prescription, User $actor, string $reason): Prescription
    {
        return DB::transaction(function () use ($prescription, $actor, $reason): Prescription {
            /** @var Prescription $locked */
            $locked = Prescription::query()->lockForUpdate()->findOrFail($prescription->id);

            if ($locked->isVoided()) {
                throw ValidationException::withMessages([
                    'prescription' => ['This prescription has already been voided.'],
                ]);
            }

            if (in_array($locked->status, [Prescription::STATUS_DISPENSED, Prescription::STATUS_COMPLETED], true)) {
                throw ValidationException::withMessages([
                    'prescription' => ['A dispensed prescription cannot be voided; issue a refund or a replacement instead.'],
                ]);
            }

            $locked->update([
                'status' => Prescription::STATUS_VOIDED,
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ]);

            AuditLog::record(
                $actor,
                'prescription.voided',
                $locked,
                newValues: ['void_reason' => $reason, 'voided_by' => $actor->id],
            );

            return $locked;
        });
    }

    /**
     * Start an amendment as a fresh draft linked to the original finalized or
     * voided prescription. Items are copied so the new record is edited
     * independently; the original is never mutated.
     */
    public function amend(Prescription $prescription, User $actor): Prescription
    {
        return DB::transaction(function () use ($prescription, $actor): Prescription {
            /** @var Prescription $locked */
            $locked = Prescription::query()->lockForUpdate()->with('items')->findOrFail($prescription->id);

            if (! in_array($locked->status, [Prescription::STATUS_FINALIZED, Prescription::STATUS_VOIDED], true)) {
                throw ValidationException::withMessages([
                    'prescription' => ['Only a finalized or voided prescription can be amended.'],
                ]);
            }

            $amendment = Prescription::create([
                'prescription_number' => 'TMP-'.uniqid(),
                'patient_id' => $locked->patient_id,
                'patient_name' => $locked->patient_name,
                'doctor_id' => $actor->id,
                'doctor_name' => $actor->name,
                'appointment_id' => $locked->appointment_id,
                'status' => 'pending',
                'notes' => $locked->notes,
                'issued_at' => now(),
                'amends_prescription_id' => $locked->id,
            ]);

            $amendment->prescription_number = sprintf('RX-%05d', $amendment->id);
            $amendment->save();

            foreach ($locked->items as $item) {
                $amendment->items()->create([
                    'medicine_name' => $item->medicine_name,
                    'dosage' => $item->dosage,
                    'frequency' => $item->frequency,
                    'duration' => $item->duration,
                    'instructions' => $item->instructions,
                    'quantity' => $item->quantity,
                ]);
            }

            AuditLog::record(
                $actor,
                'prescription.amended',
                $amendment,
                oldValues: ['amends_prescription_id' => $locked->id],
                newValues: ['patient_id' => $locked->patient_id, 'item_count' => $amendment->items()->count()],
            );

            return $amendment;
        });
    }
}
