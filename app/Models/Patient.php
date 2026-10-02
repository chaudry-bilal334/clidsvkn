<?php

namespace App\Models;

use App\Models\Concerns\BranchScope;
use App\Services\PatientService;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['branch_id', 'name', 'mrn', 'cnic', 'cnic_hash', 'email', 'email_normalized', 'phone', 'phone_normalized', 'date_of_birth', 'gender', 'address'])]
#[Hidden(['cnic'])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use BranchScope, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'cnic' => 'encrypted',
        ];
    }

    /**
     * Recompute the normalized identity search columns for this record.
     *
     * The CNIC blind index, normalized phone and normalized email back the
     * indexed exact-duplicate lookups; they are derived from the column's
     * own (decrypted) value so they stay correct no matter how the record
     * is written.
     */
    public function refreshIdentitySearch(): void
    {
        $this->cnic_hash = $this->cnic === null ? null : PatientService::cnicHash($this->cnic);
        $this->phone_normalized = $this->phone === null ? null : PatientService::normalizePhone($this->phone);
        $this->email_normalized = $this->email === null ? null : PatientService::normalizeEmail($this->email);
    }

    /**
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Prescription, $this>
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    /**
     * @return HasMany<LabOrder, $this>
     */
    public function labOrders(): HasMany
    {
        return $this->hasMany(LabOrder::class);
    }

    /**
     * @return HasMany<QueueEntry, $this>
     */
    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }

    /**
     * @return HasMany<Encounter, $this>
     */
    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    /**
     * @return HasMany<PatientMedicalHistory, $this>
     */
    public function medicalHistories(): HasMany
    {
        return $this->hasMany(PatientMedicalHistory::class);
    }

    /**
     * @return HasMany<PatientAllergy, $this>
     */
    public function patientAllergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class);
    }

    /**
     * @return HasMany<Consent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    /**
     * @return HasMany<MedicationReconciliation, $this>
     */
    public function medicationReconciliations(): HasMany
    {
        return $this->hasMany(MedicationReconciliation::class);
    }
}
