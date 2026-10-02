<?php

namespace App\Policies;

use App\Models\Followup;
use App\Models\User;

class FollowupPolicy
{
    /**
     * Roles allowed to view the follow-up worklist.
     *
     * @var array<int, string>
     */
    private const VIEWER_ROLES = ['Admin', 'Doctor', 'Receptionist'];

    /**
     * Roles allowed to schedule and action follow-up tasks.
     *
     * @var array<int, string>
     */
    private const MANAGER_ROLES = ['Admin', 'Doctor', 'Receptionist'];

    /**
     * Determine whether the user can view any follow-ups.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, self::VIEWER_ROLES, true);
    }

    /**
     * Determine whether the user can view the follow-up.
     */
    public function view(User $user, Followup $followup): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create follow-ups.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can update the follow-up.
     */
    public function update(User $user, Followup $followup): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can complete the follow-up.
     */
    public function complete(User $user, Followup $followup): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can cancel the follow-up.
     */
    public function cancel(User $user, Followup $followup): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }
}
