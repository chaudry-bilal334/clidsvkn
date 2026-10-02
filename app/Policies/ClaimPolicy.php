<?php

namespace App\Policies;

use App\Models\Claim;
use App\Models\User;

class ClaimPolicy
{
    /**
     * Roles allowed to create, edit and settle claims.
     *
     * @var array<int, string>
     */
    private const MANAGER_ROLES = ['Admin', 'Accountant', 'Receptionist'];

    /**
     * Determine whether the user can view any claims.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'Doctor' || in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Claim $claim): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create claims.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can update claims.
     */
    public function update(User $user, Claim $claim): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can delete claims.
     */
    public function delete(User $user, Claim $claim): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can advance a claim through its lifecycle.
     */
    public function transitionStatus(User $user, Claim $claim): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Claim $claim): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Claim $claim): bool
    {
        return in_array($user->role, self::MANAGER_ROLES, true);
    }
}
