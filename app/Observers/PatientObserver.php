<?php

namespace App\Observers;

use App\Models\Patient;
use App\Services\PatientService;

class PatientObserver
{
    /**
     * Keep the identity search columns synchronized before insert/update.
     *
     * The casting pipeline decrypts cnic on read; we can read it from the
     * model's attributes and derive the indexed columns from the plaintext.
     *
     * Each derived column is recomputed only when its source column is
     * actually dirty. The earlier "…or the derived column is null" clause
     * self-healed rows created before these columns existed, but it now means
     * a nulled blind index would be recomputed on *any* save — so a patient
     * whose `cnic_hash` was deliberately released during the CNIC uniqueness
     * migration would fail an unrelated address edit against the unique index.
     * Both migrations backfill these columns, so the backstop is redundant.
     */
    public function saving(Patient $patient): void
    {
        $dirtyKeys = array_keys($patient->getDirty());

        if (in_array('cnic', $dirtyKeys, true)) {
            $patient->cnic_hash = $patient->cnic === null ? null : PatientService::cnicHash($patient->cnic);
        }

        if (in_array('phone', $dirtyKeys, true)) {
            $patient->phone_normalized = $patient->phone === null ? null : PatientService::normalizePhone($patient->phone);
        }

        if (in_array('email', $dirtyKeys, true)) {
            $patient->email_normalized = $patient->email === null ? null : PatientService::normalizeEmail($patient->email);
        }
    }
}
