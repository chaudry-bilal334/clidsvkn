<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PatientService
{
    /**
     * Threshold above which a Jaro-Winkler name + DOB similarity is
     * considered a possible duplicate match.
     */
    private const JARO_WINKLER_THRESHOLD = 0.88;

    /**
     * Tables whose patient_id must be reassigned during a merge.
     *
     * The users table is handled separately (one-to-one patient ↔ user
     * account intent; if both patients already have a user the merge is
     * refused rather than silently breaking the constraint).
     *
     * @var array<int, string>
     */
    private const CHILD_TABLES = [
        'appointments',
        'invoices',
        'prescriptions',
        'lab_orders',
        'queue_entries',
        'encounters',
        'medical_documents',
        'telehealth_sessions',
        'claims',
        'clinical_decision_supports',
        'data_export_requests',
        'patient_medical_histories',
        'medication_reconciliations',
        'followups',
        // Consent history is compliance evidence: dropping it would erase the
        // record of what the patient agreed to, and when they withdrew it.
        'consents',
    ];

    /**
     * Allergy severity ordering, least to most severe.
     *
     * Used when a merge collapses two records of the same allergen: the more
     * severe of the two wins, because understating an allergy is the one
     * direction of error the CDSS cannot recover from.
     *
     * @var array<string, int>
     */
    private const SEVERITY_RANK = [
        'mild' => 1,
        'moderate' => 2,
        'severe' => 3,
        'life_threatening' => 4,
    ];

    // -----------------------------------------------------------------------
    // Normalization
    // -----------------------------------------------------------------------

    /**
     * Deterministic blind index for an exact CNIC lookup.
     *
     * patients.cnic is stored with Laravel's non-deterministic `encrypted`
     * cast (random IV per write), which prevents any indexed comparison.
     * Hashing with the app key (HMAC-SHA256) yields a searchable,
     * privacy-safe value that cannot be reversed without the key.
     */
    public static function cnicHash(string $cnic): string
    {
        return hash_hmac('sha256', self::normalizeCnic($cnic), (string) config('app.key'));
    }

    /**
     * Strip everything except digits and the leading +.
     *
     * Returns the normalized string or null when the input is empty.
     */
    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = preg_replace('/[^0-9+]/', '', $value);

        return $normalized === '' || $normalized === '+' ? null : $normalized;
    }

    /**
     * Lowercase + trim; null for blank input.
     */
    public static function normalizeEmail(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return strtolower(trim($value));
    }

    /**
     * Collapse whitespace, lowercase; null for blank input.
     */
    public static function normalizeName(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }

    /**
     * Reduce a CNIC to its digits so spacing and separator style cannot create
     * a second identity out of the same number.
     *
     * Public because the CNIC blind-index uniqueness check needs the exact same
     * normalization the hash was built from — a different rule here would let
     * the same number slip past the check that guards the unique index.
     */
    public static function normalizeCnic(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return preg_replace('/[^0-9]/', '', $value);
    }

    // -----------------------------------------------------------------------
    // MRN allocation
    // -----------------------------------------------------------------------

    /**
     * Allocate and persist the next Medical Record Number under a row lock.
     *
     * Serializes MRN allocation through the single-row mrn_sequences table so
     * concurrent patient registration can never mint the same number. The
     * unique patients.mrn constraint remains as the database-level backstop.
     */
    public function nextMrn(): string
    {
        return DB::transaction(fn (): string => $this->allocate());
    }

    /**
     * Create a patient and allocate its MRN in the same transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createPatient(array $data): Patient
    {
        return DB::transaction(function () use ($data): Patient {
            $mrn = $this->allocate();

            return Patient::create($data + ['mrn' => $mrn]);
        });
    }

    // -----------------------------------------------------------------------
    // Duplicate detection
    // -----------------------------------------------------------------------

    /**
     * Find records that could be the same person as the submitted data.
     *
     * A candidate pool is seeded from the indexed normalized-identity columns
     * (CNIC hash, phone_normalized, email_normalized) plus a loose name LIKE.
     * Each candidate is scored: a single exact-identifier match (CNIC / phone /
     * email) returns the candidate with score ≥ 1.0. A Jaro-Winkler name + DOB
     * similarity exceeding the threshold returns the candidate with that score.
     *
     * Returns results in descending score order.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{patient: Patient, score: float, reasons: array<int, string>}>
     */
    public function findPossibleDuplicates(array $data): array
    {
        $candidateIds = $this->candidateIds($data);

        if ($candidateIds === []) {
            return [];
        }

        return Patient::query()
            ->whereKey($candidateIds)
            ->get()
            ->map(fn (Patient $patient): array => $this->score($patient, $data))
            ->filter(fn (array $result): bool => $result['score'] >= 0.5)
            ->sortByDesc(fn (array $result): float => $result['score'])
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------------
    // Merge
    // -----------------------------------------------------------------------

    /**
     * Reassign every child record from the duplicate patient to the survivor,
     * promote missing identity fields from the duplicate onto the survivor,
     * then delete the duplicate — all within one transaction.
     *
     * If both patients have linked user accounts the merge is refused: the
     * one-to-one user ↔ patient intent is a human-resolvable constraint, not
     * something the merge should silently undo.
     *
     * @param  array<string, mixed>|null  $promote  Optional explicit fields to force onto the survivor.
     */
    public function mergePatients(
        Patient $survivor,
        Patient $duplicate,
        User $actor,
        ?string $reason = null,
        ?array $promote = null,
    ): Patient {
        abort_if($survivor->getKey() === $duplicate->getKey(), 422, 'Cannot merge a patient into itself.');
        abort_unless($survivor->branch_id === $duplicate->branch_id, 422, 'Merging patients across branches is not allowed.');

        $survivorOwner = $survivor->user()->first();
        $duplicateOwner = $duplicate->user()->first();

        if ($survivorOwner !== null && $duplicateOwner !== null) {
            abort(422, 'Both patient records have linked user accounts (IDs '.$survivorOwner->id.', '.$duplicateOwner->id.'). Unlink or deactivate one before merging.');
        }

        $old = $survivor->only(['mrn', 'cnic', 'email', 'phone', 'date_of_birth', 'gender', 'address']);

        return DB::transaction(function () use ($survivor, $duplicate, $actor, $reason, $promote, $duplicateOwner, $old): Patient {
            // A linked user on the duplicate moves with its patient.
            if ($duplicateOwner !== null) {
                $duplicateOwner->patient_id = $survivor->getKey();
                $duplicateOwner->save();
            }

            foreach (self::CHILD_TABLES as $table) {
                DB::table($table)
                    ->where('patient_id', $duplicate->getKey())
                    ->update(['patient_id' => $survivor->getKey()]);
            }

            // Allergies need their own pass: `patient_allergies` is unique on
            // (patient_id, allergen_id, is_active), so a plain re-parent blows
            // up whenever both records list the same allergen — and leaving it
            // out entirely would let the cascade delete a recorded allergy.
            $this->reparentAllergies($survivor->getKey(), $duplicate->getKey());

            // Release the duplicate's CNIC blind index before the survivor is
            // promoted below. `patients.cnic_hash` is uniquely indexed, so
            // handing the duplicate's CNIC to the survivor while the duplicate
            // row still holds it is a constraint violation. Only the index is
            // cleared — the row, and its encrypted CNIC, live until the delete
            // a line below.
            if ($duplicate->cnic_hash !== null) {
                DB::table('patients')
                    ->where('id', $duplicate->getKey())
                    ->update(['cnic_hash' => null]);
            }

            // Promote missing identity fields from duplicate → survivor.
            $this->applyPromotedFields($survivor, $duplicate, $promote);

            $survivor->refreshIdentitySearch();
            $survivor->save();

            // MRNs stay unique: the duplicate's MRN is freed when its row drops.
            $duplicate->delete();

            $auditNew = $survivor->only([
                'mrn', 'cnic', 'email', 'phone', 'date_of_birth', 'gender', 'address',
            ]);

            if ($reason !== null) {
                $auditNew['merge_reason'] = $reason;
            }

            AuditLog::record($actor, 'patient.merged', $survivor, $old, $auditNew);

            return $survivor;
        });
    }

    /**
     * Whether the patient has any attached clinical/history record.
     *
     * Enforces the owner's indefinite-retention decision (UNRESOLVED_DECISIONS
     * Retention row): a patient with any clinical history must never be
     * deletable — deletion is BLOCKED outright rather than soft-deleted,
     * because even a soft-deleted row could later be purged and would hide the
     * record from view. Only truly empty patients (mistaken/duplicate
     * registrations) may be deleted, matching the duplicate-merge path which
     * hard-deletes the emptied duplicate after reparenting.
     *
     * Checks the same child tables the merge path reparents (CHILD_TABLES),
     * plus `patient_allergies` — which carries patient_id and is reparented by
     * a dedicated dedupe pass rather than the blanket loop, so it is listed
     * here explicitly rather than being silently missed.
     */
    public function hasClinicalHistory(Patient $patient): bool
    {
        $tables = [
            ...self::CHILD_TABLES,
            'patient_allergies',
        ];

        foreach ($tables as $table) {
            if (DB::table($table)->where('patient_id', $patient->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    private function allocate(): string
    {
        $next = (int) DB::table('mrn_sequences')->lockForUpdate()->value('last_value') + 1;

        DB::table('mrn_sequences')->where('id', 1)->update(['last_value' => $next]);

        return 'MRN-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    private function candidateIds(array $data): array
    {
        $candidates = collect();

        if ($cnic = self::normalizeCnic($data['cnic'] ?? null)) {
            $candidates->push(...Patient::query()
                ->where('cnic_hash', self::cnicHash($cnic))
                ->pluck('id'));
        }

        if ($phone = self::normalizePhone($data['phone'] ?? null)) {
            $candidates->push(...Patient::query()
                ->where('phone_normalized', $phone)
                ->pluck('id'));
        }

        if ($email = self::normalizeEmail($data['email'] ?? null)) {
            $candidates->push(...Patient::query()
                ->where('email_normalized', $email)
                ->pluck('id'));
        }

        if ($name = self::normalizeName($data['name'] ?? null)) {
            $tokens = array_values(array_filter(
                explode(' ', $name),
                fn (string $token): bool => mb_strlen($token) >= 3,
            ));

            if ($tokens !== []) {
                $query = Patient::query()->where(function ($query) use ($tokens): void {
                    $first = array_shift($tokens);

                    $query->where('name', 'like', '%'.$first.'%');

                    foreach (array_slice($tokens, 0, 2) as $token) {
                        $query->orWhere('name', 'like', '%'.$token.'%');
                    }
                });

                $candidates->push(...$query->pluck('id'));
            }
        }

        return $candidates
            ->unique()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{patient: Patient, score: float, reasons: array<int, string>}
     */
    private function score(Patient $patient, array $data): array
    {
        $reasons = [];
        $exact = 0;

        if ($cnic = self::normalizeCnic($data['cnic'] ?? null)) {
            if ($patient->cnic_hash !== null && $patient->cnic_hash === self::cnicHash($cnic)) {
                $reasons[] = 'cnic';
                $exact++;
            }
        }

        if ($phone = self::normalizePhone($data['phone'] ?? null)) {
            if ($patient->phone_normalized !== null && $patient->phone_normalized === $phone) {
                $reasons[] = 'phone';
                $exact++;
            }
        }

        if ($email = self::normalizeEmail($data['email'] ?? null)) {
            if ($patient->email_normalized !== null && $patient->email_normalized === $email) {
                $reasons[] = 'email';
                $exact++;
            }
        }

        $nameSimilarity = $this->nameSimilarity($patient, $data);

        if ($nameSimilarity >= self::JARO_WINKLER_THRESHOLD) {
            $reasons[] = 'name';
        }

        if ($exact > 0) {
            $reasons[] = 'exact';
        }

        $score = max($nameSimilarity, (float) $exact);

        return [
            'patient' => $patient,
            'score' => (float) round($score, 4),
            'reasons' => array_unique($reasons),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nameSimilarity(Patient $patient, array $data): float
    {
        $left = self::normalizeName((string) ($data['name'] ?? ''));
        $right = self::normalizeName((string) $patient->name);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        $similarity = $this->jaroWinkler($left, $right);

        $leftDob = $data['date_of_birth'] ?? null;
        $rightDob = $patient->date_of_birth;

        // Same date of birth corroborates a name-only fuzzy match.
        if ($similarity >= self::JARO_WINKLER_THRESHOLD
            && $leftDob !== null
            && $rightDob !== null
            && $leftDob == $rightDob->format('Y-m-d')) {
            $similarity = min(1.0, $similarity + 0.05);
        }

        return $similarity;
    }

    /**
     * Copy missing identity fields from the duplicate onto the survivor.
     *
     * For non-null fields on the duplicate where the survivor has a null
     * value, the duplicate's value is promoted onto the survivor. Explicit
     * overrides in $promote always win.
     *
     * @param  array<string, mixed>|null  $promote
     */
    /**
     * Move the duplicate's allergy records onto the survivor.
     *
     * `patient_allergies` is unique on (patient_id, allergen_id, is_active), so
     * an allergen recorded on both patients has to be collapsed rather than
     * copied. The survivor's row is kept and enriched from the duplicate
     * rather than the other way round: the more severe severity wins, the
     * earliest known reaction date wins, and a recorded reaction description
     * is carried over if the survivor has none. Collapsing this way loses no
     * clinical signal, whereas dropping the row would let a recorded severe
     * allergy disappear from the CDSS check.
     */
    private function reparentAllergies(int $survivorId, int $duplicateId): void
    {
        $duplicateAllergies = DB::table('patient_allergies')
            ->where('patient_id', $duplicateId)
            ->get();

        foreach ($duplicateAllergies as $allergy) {
            $existing = DB::table('patient_allergies')
                ->where('patient_id', $survivorId)
                ->where('allergen_id', $allergy->allergen_id)
                ->where('is_active', $allergy->is_active)
                ->first();

            if ($existing === null) {
                DB::table('patient_allergies')
                    ->where('id', $allergy->id)
                    ->update(['patient_id' => $survivorId]);

                continue;
            }

            $merged = [
                'severity' => $this->moreSevere((string) $existing->severity, (string) $allergy->severity),
                'reaction' => $existing->reaction ?? $allergy->reaction,
                'first_reaction_at' => $this->earliestDate($existing->first_reaction_at, $allergy->first_reaction_at),
            ];

            DB::table('patient_allergies')
                ->where('id', $existing->id)
                ->update($merged);

            // The duplicate's row for this allergen is now fully represented on
            // the survivor's row, so the now-redundant copy is removed rather
            // than left to violate the unique index.
            DB::table('patient_allergies')->where('id', $allergy->id)->delete();
        }
    }

    /**
     * The more severe of two allergy severities, defaulting to `moderate` for
     * anything unrecognised so an unknown value never silently downgrades.
     */
    private function moreSevere(string $left, string $right): string
    {
        $leftRank = self::SEVERITY_RANK[$left] ?? 2;
        $rightRank = self::SEVERITY_RANK[$right] ?? 2;

        return $leftRank >= $rightRank ? $left : $right;
    }

    /**
     * The earlier of two nullable dates, keeping a non-null value over null.
     */
    private function earliestDate(mixed $left, mixed $right): ?string
    {
        $values = array_values(array_filter([$left, $right], static fn (mixed $value): bool => $value !== null && $value !== ''));

        if ($values === []) {
            return null;
        }

        return (string) min($values);
    }

    private function applyPromotedFields(Patient $survivor, Patient $duplicate, ?array $promote): void
    {
        $survivor->fill($promote ?? []);

        if ($survivor->cnic === null && $duplicate->cnic !== null) {
            $survivor->cnic = $duplicate->cnic;
        }
        if ($survivor->email === null && $duplicate->email !== null) {
            $survivor->email = $duplicate->email;
        }
        if ($survivor->phone === null && $duplicate->phone !== null) {
            $survivor->phone = $duplicate->phone;
        }
        if ($survivor->date_of_birth === null && $duplicate->date_of_birth !== null) {
            $survivor->date_of_birth = $duplicate->date_of_birth;
        }
        if ($survivor->gender === null && $duplicate->gender !== null) {
            $survivor->gender = $duplicate->gender;
        }
        if ($survivor->address === null && $duplicate->address !== null) {
            $survivor->address = $duplicate->address;
        }
    }

    // -----------------------------------------------------------------------
    // Jaro-Winkler
    // -----------------------------------------------------------------------

    /**
     * Jaro-Winkler string similarity for fuzzy name matching.
     *
     * @return float 0.0 (no match) to 1.0 (identical)
     */
    private function jaroWinkler(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $aLen = strlen($a);
        $bLen = strlen($b);

        if ($aLen === 0 || $bLen === 0) {
            return 0.0;
        }

        $matchDistance = max($aLen, $bLen) / 2 - 1;
        $aMatches = array_fill(0, $aLen, false);
        $bMatches = array_fill(0, $bLen, false);

        $matches = 0;

        for ($i = 0; $i < $aLen; $i++) {
            $start = max(0, (int) floor($i - $matchDistance));
            $end = min($bLen - 1, (int) ceil($i + $matchDistance));

            for ($j = $start; $j <= $end; $j++) {
                if ($bMatches[$j] || $a[$i] !== $b[$j]) {
                    continue;
                }

                $aMatches[$i] = true;
                $bMatches[$j] = true;
                $matches++;

                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $k = 0;

        for ($i = 0; $i < $aLen; $i++) {
            if (! $aMatches[$i]) {
                continue;
            }

            while (! $bMatches[$k]) {
                $k++;
            }

            if ($a[$i] !== $b[$k]) {
                $transpositions++;
            }

            $k++;
        }

        $jaro = (
            ($matches / $aLen)
            + ($matches / $bLen)
            + (($matches - $transpositions / 2) / $matches)
        ) / 3;

        $prefix = 0;

        for ($i = 0; $i < min(4, $aLen, $bLen); $i++) {
            if ($a[$i] === $b[$i]) {
                $prefix++;
            } else {
                break;
            }
        }

        return $jaro + $prefix * 0.1 * (1 - $jaro);
    }
}
