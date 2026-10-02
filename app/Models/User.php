<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'notification_preferences', 'patient_id', 'phone', 'branch_id', 'is_cross_branch', 'two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const CONSENT_APPOINTMENTS = 'appointments';

    public const CONSENT_LOW_STOCK = 'low_stock';

    public const CONSENT_SECURITY = 'security';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'Receptionist',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
            'is_cross_branch' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * Determine whether the user has consented to broadcasts for the given notification channel.
     *
     * Consent defaults to true unless the user has explicitly opted out in their
     * notification_preferences, keeping existing subscribers unaffected.
     */
    public function consentsTo(string $channel): bool
    {
        return $this->notification_preferences[$channel] ?? true;
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Whether the account has completed 2FA setup.
     */
    public function twoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Whether this account must pass a 2FA challenge before sensitive access.
     *
     * Mandatory for Admin and Doctor roles once 2FA has been enabled.
     */
    public function twoFactorRequired(): bool
    {
        return $this->twoFactorEnabled() && in_array($this->role, ['Admin', 'Doctor'], true);
    }

    /**
     * @return HasMany<DoctorSchedule, $this>
     */
    public function doctorSchedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class, 'doctor_id');
    }

    /**
     * @return HasMany<DoctorTimeOff, $this>
     */
    public function doctorTimeOffs(): HasMany
    {
        return $this->hasMany(DoctorTimeOff::class, 'doctor_id');
    }

    /**
     * @return HasOne<Doctor, $this>
     */
    public function doctorProfile(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    /**
     * The capabilities granted to the user based on their role.
     */
    protected function permissions(): Attribute
    {
        return Attribute::make(
            get: fn () => match ($this->role) {
                'Admin' => ['*'],
                'Doctor' => [
                    'patients.view',
                    'patients.manage',
                    'appointments.view',
                    'appointments.manage',
                    'prescriptions.manage',
                    'encounters.view',
                    'encounters.manage',
                    'lab.verify',
                    'pharmacy.view',
                    'billing.view',
                    'reports.view',
                    'claims.view',
                    'cdss.view',
                    'cdss.manage',
                    'documents.view',
                    'documents.manage',
                    'portal.view',
                    'portal.manage',
                    'crm.view',
                    'followups.view',
                    'followups.manage',
                    'notifications.view',
                    'notifications.manage',
                ],
                'Receptionist' => [
                    'patients.view',
                    'patients.manage',
                    'appointments.view',
                    'appointments.manage',
                    'notifications.view',
                    'notifications.manage',
                    'claims.view',
                    'claims.manage',
                    'documents.view',
                    'crm.view',
                    'crm.manage',
                    'followups.view',
                    'followups.manage',
                    'ai_content.view',
                    'ai_content.manage',
                ],
                'Accountant' => [
                    'billing.view',
                    'billing.manage',
                    'reports.view',
                    'claims.view',
                    'claims.manage',
                    'crm.view',
                    'crm.manage',
                    'expenses.view',
                    'expenses.manage',
                    'expense_categories.view',
                    'expense_categories.manage',
                    'notifications.view',
                    'notifications.manage',
                ],
                'Pharmacist' => [
                    'pharmacy.view',
                    'pharmacy.manage',
                    'prescriptions.view',
                    'lab.verify',
                    'inventory.manage',
                    'reports.view',
                    'documents.view',
                    'suppliers.view',
                    'suppliers.manage',
                    'purchases.view',
                    'purchases.manage',
                    'expenses.view',
                    'expenses.manage',
                    'notifications.view',
                    'notifications.manage',
                ],
                'Patient' => [
                    'portal.view',
                    'portal.manage',
                ],
                default => [],
            },
        );
    }
}
