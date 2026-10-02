<?php

namespace App\Policies;

use App\Models\ClinicalDecisionSupport;
use App\Models\User;

class CdssPolicy
{
    /**
     * Clinical decision support is reserved for licensed prescribers and admins.
     *
     * @var array<int, string>
     */
    private const CLINICAL_ROLES = ['Admin', 'Doctor'];

    /**
     * Determine whether the user can evaluate prescriptions and view alerts.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, self::CLINICAL_ROLES, true);
    }

    /**
     * Determine whether the user can view a specific alert.
     */
    public function view(User $user, ClinicalDecisionSupport $alert): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can run a CDS evaluation for a patient.
     */
    public function evaluate(User $user): bool
    {
        return in_array($user->role, self::CLINICAL_ROLES, true);
    }

    /**
     * Determine whether the user can acknowledge an alert.
     */
    public function acknowledge(User $user, ClinicalDecisionSupport $alert): bool
    {
        return $this->viewAny($user);
    }
}
