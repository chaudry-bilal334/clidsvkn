<?php

namespace App\Console\Commands;

use App\Models\PatientMedicalHistory;
use App\Models\Prescription;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clinic:scan-allergy-notes')]
#[Description('Report patients whose allergy history lives only in free-text prescription notes and would lose CDSS allergy coverage once the notes scrape is removed')]
class ScanAllergyNotesCommand extends Command
{
    /**
     * @var array<string, array<int, string>>
     */
    private const FAMILIES = [
        'penicillin' => ['penicillin'],
        'sulfa' => ['sulfa', 'sulfonamide', 'sulfamethoxazole', 'sulfasalazine', 'sulfadiazine'],
        'nsaid' => ['nsaid', 'nsaids', 'ibuprofen', 'naproxen', 'aspirin', 'diclofenac'],
    ];

    /**
     * @var array<int, string>
     */
    private const ALLERGY_KEYWORDS = [
        'allergy',
        'allergic',
        'hypersensitive',
        'anaphyl',
        'rash',
        'swelling',
        'hives',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $findings = $this->findings();

        if ($findings === []) {
            $this->info('No free-text allergy mentions found in active prescription notes.');

            return self::SUCCESS;
        }

        $structured = collect($findings)->filter(fn (array $row) => $row['has_structured_coverage'])->count();
        $uncovered = count($findings) - $structured;

        $this->table(
            ['Patient ID', 'Patient', 'MRN', 'Matched family', 'Structured allergy already recorded?', 'Notes excerpt'],
            $findings,
        );

        $this->newLine();
        $this->info("Found {$structured} patient(s) already covered by structured allergy history and {$uncovered} patient(s) that would lose CDSS allergy coverage.");

        return self::SUCCESS;
    }

    /**
     * Patients whose active prescription notes mention an allergen family and
     * an allergy keyword, mirroring the former free-text scrape the CDSS
     * relied on before structured patient_medical_history data.
     *
     * @return array<int, array{patient_id: int, patient_name: string, mrn: string|null, family: string, notes: string, has_structured_coverage: bool}>
     */
    public function findings(): array
    {
        $rows = [];

        $prescriptions = Prescription::query()
            ->with('patient:id,name,mrn')
            ->whereIn('status', ['pending', 'dispensed'])
            ->whereNotNull('notes')
            ->get(['id', 'patient_id', 'patient_name', 'notes']);

        $coveredPatientIds = PatientMedicalHistory::query()
            ->where('category', PatientMedicalHistory::CATEGORY_ALLERGY)
            ->where('is_active', true)
            ->pluck('patient_id')
            ->all();

        foreach ($prescriptions as $prescription) {
            $notes = mb_strtolower((string) $prescription->notes);

            $matchedFamily = null;

            foreach (self::FAMILIES as $family => $tokens) {
                $hasFamily = collect($tokens)->contains(fn (string $token) => str_contains($notes, $token));
                $hasKeyword = collect(self::ALLERGY_KEYWORDS)->contains(fn (string $keyword) => str_contains($notes, $keyword));

                if ($hasFamily && $hasKeyword) {
                    $matchedFamily = $family;
                    break;
                }
            }

            if ($matchedFamily === null) {
                continue;
            }

            $patient = $prescription->patient;

            $rows[] = [
                'patient_id' => $prescription->patient_id,
                'patient_name' => $patient?->name ?? $prescription->patient_name,
                'mrn' => $patient?->mrn,
                'family' => $matchedFamily,
                'notes' => mb_substr((string) $prescription->notes, 0, 120),
                'has_structured_coverage' => in_array($prescription->patient_id, $coveredPatientIds, true),
            ];
        }

        usort($rows, fn (array $a, array $b) => $a['patient_id'] <=> $b['patient_id']);

        return $rows;
    }
}
