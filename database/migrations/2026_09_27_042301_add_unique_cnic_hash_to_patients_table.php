<?php

use App\Models\Patient;
use App\Services\PatientService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One patient per CNIC (PRD §4, §12).
 *
 * `patients.cnic` is stored with Laravel's non-deterministic `encrypted` cast,
 * so it can never be indexed. `cnic_hash` is the deterministic HMAC blind index
 * that stands in for it, and until now it carried only a plain (non-unique)
 * index. That made CNIC uniqueness an application-level courtesy: two
 * registrations that raced, or arrived through two different write paths, both
 * committed and produced two patients claiming the same national identity
 * number. This migration moves the guarantee down into the database.
 *
 * NULLs stay permitted — most patients have no CNIC on file, and SQL unique
 * semantics already allow any number of NULLs in a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfillHashes();
        $this->releaseDuplicateHashes();

        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['cnic_hash']);
            $table->unique('cnic_hash', 'patients_cnic_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique('patients_cnic_hash_unique');
            $table->index('cnic_hash');
        });
    }

    /**
     * Recompute the blind index for every patient that has a CNIC but no hash.
     *
     * Has to go through the Eloquent model: `cnic` is an `encrypted` cast, so
     * a query-builder read returns ciphertext and hashing that would produce a
     * value that never matches a real lookup.
     */
    private function backfillHashes(): void
    {
        Patient::query()
            ->whereNull('cnic_hash')
            ->chunkById(200, function ($patients): void {
                foreach ($patients as $patient) {
                    if ($patient->cnic === null) {
                        continue;
                    }

                    $patient->cnic_hash = PatientService::cnicHash($patient->cnic);
                    $patient->saveQuietly();
                }
            });
    }

    /**
     * Release the blind index on the newer of any duplicate CNIC pair.
     *
     * Only `cnic_hash` is nulled — the encrypted `cnic` itself is left in
     * place, so no identity data is destroyed and the row is still fully
     * readable. The oldest record keeps the indexed lookup and stays the one
     * the app treats as canonical. Because the observer now recomputes the
     * hash only when `cnic` actually changes, an unrelated edit to a released
     * row will not resurrect the conflict; actually changing that patient's
     * CNIC to the duplicated value will now fail against the unique index
     * instead of quietly creating a third patient.
     *
     * These pre-existing duplicates should be resolved through the duplicate
     * detection and merge tooling — see PatientService::findDuplicates().
     */
    private function releaseDuplicateHashes(): void
    {
        $duplicates = DB::table('patients')
            ->whereNotNull('cnic_hash')
            ->groupBy('cnic_hash')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('cnic_hash');

        foreach ($duplicates as $hash) {
            $ids = DB::table('patients')
                ->where('cnic_hash', $hash)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1);

            DB::table('patients')
                ->whereIn('id', $ids)
                ->update(['cnic_hash' => null]);
        }
    }
};
