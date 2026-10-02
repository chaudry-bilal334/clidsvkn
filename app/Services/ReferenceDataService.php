<?php

namespace App\Services;

use App\Models\Allergen;
use App\Models\AuditLog;
use App\Models\DosageRule;
use App\Models\DrugInteractionRule;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ReferenceDataService
{
    /**
     * List pending (unapproved) reference data items.
     */
    public function pending(?string $type = null): array
    {
        $pending = [];

        if ($type === null || $type === 'allergens') {
            $pending['allergens'] = Allergen::where('is_approved', false)
                ->get(['id', 'name', 'family', 'aliases', 'is_approved', 'approved_by', 'approved_at'])
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'family' => $a->family ?? '',
                    'aliases' => $a->aliases ?? [],
                    'is_approved' => $a->is_approved,
                    'approved_by' => $a->approvedBy?->name,
                    'approved_at' => $a->approved_at?->toISO8601String(),
                ])->values()->all();
        }

        if ($type === null || $type === 'drug_interactions') {
            $pending['drug_interactions'] = DrugInteractionRule::where('is_approved', false)
                ->get(['id', 'medicine_a', 'medicine_b', 'severity', 'guidance', 'is_approved', 'approved_by', 'approved_at'])
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'medicine_a' => $i->medicine_a,
                    'medicine_b' => $i->medicine_b,
                    'severity' => $i->severity,
                    'guidance' => $i->guidance,
                    'is_approved' => $i->is_approved,
                    'approved_by' => $i->approvedBy?->name,
                    'approved_at' => $i->approved_at?->toISO8601String(),
                ])->values()->all();
        }

        if ($type === null || $type === 'dosage_rules') {
            $pending['dosage_rules'] = DosageRule::where('is_approved', false)
                ->get(['id', 'medicine_name', 'aliases', 'max_daily_mg', 'is_approved', 'approved_by', 'approved_at'])
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'medicine_name' => $d->medicine_name,
                    'aliases' => $d->aliases ?? [],
                    'max_daily_mg' => $d->max_daily_mg,
                    'is_approved' => $d->is_approved,
                    'approved_by' => $d->approvedBy?->name,
                    'approved_at' => $d->approved_at?->toISO8601String(),
                ])->values()->all();
        }

        return $type === null ? $pending : ($pending[$type] ?? []);
    }

    /**
     * Approve a reference data item.
     */
    public function approve(string $type, int $id, User $actor): object
    {
        $model = $this->getModel($type, $id);

        $model->update([
            'is_approved' => true,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        AuditLog::record(
            $actor,
            'reference_data.approved',
            $model,
            newValues: [
                'type' => $type,
                'id' => $model->id,
                'is_approved' => true,
            ],
        );

        return $model;
    }

    /**
     * Reject/reset a reference data item to pending.
     */
    public function reject(string $type, int $id, User $actor): object
    {
        $model = $this->getModel($type, $id);

        $model->update([
            'is_approved' => false,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        AuditLog::record(
            $actor,
            'reference_data.rejected',
            $model,
            newValues: [
                'type' => $type,
                'id' => $model->id,
                'is_approved' => false,
            ],
        );

        return $model;
    }

    private function getModel(string $type, int $id): object
    {
        return match ($type) {
            'allergens' => Allergen::findOrFail($id),
            'drug_interactions' => DrugInteractionRule::findOrFail($id),
            'dosage_rules' => DosageRule::findOrFail($id),
            default => throw ValidationException::withMessages([
                'type' => 'Invalid reference data type.',
            ]),
        };
    }
}
