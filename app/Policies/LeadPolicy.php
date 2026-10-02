<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    /**
     * Roles allowed to view the CRM pipeline.
     *
     * @var array<int, string>
     */
    private const VIEWER_ROLES = ['Admin', 'Accountant', 'Receptionist', 'Doctor'];

    /**
     * Roles allowed to create, edit and advance leads.
     *
     * @var array<int, string>
     */
    private const MANAGER_ROLES = ['Admin', 'Accountant', 'Receptionist'];

    /**
     * Determine whether the user can view any leads.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, self::VIEWER_ROLES, true);
    }

    /**
     * Determine whether the user can view the lead.
     */
    public function view(User $user, Lead $lead): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create leads.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can update the lead.
     */
    public function update(User $user, Lead $lead): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can delete the lead.
     */
    public function delete(User $user, Lead $lead): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can advance the lead through the pipeline.
     */
    public function transition(User $user, Lead $lead): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can log a communication against the lead.
     */
    public function recordCommunication(User $user, Lead $lead): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Lead $lead): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Lead $lead): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }
}
