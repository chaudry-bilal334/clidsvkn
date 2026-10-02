<?php

namespace App\Policies;

use App\Models\DataExportRequest;
use App\Models\Patient;
use App\Models\SystemSetting;
use App\Models\User;

class SystemAdminPolicy
{
    /**
     * Determine whether the user can view any system settings.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can view a system setting.
     */
    public function view(User $user, SystemSetting $systemSetting): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can create system settings.
     */
    public function create(User $user): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can update system settings.
     */
    public function update(User $user, SystemSetting $systemSetting): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can delete system settings.
     */
    public function delete(User $user, SystemSetting $systemSetting): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can manage system settings (alias for create/update/delete).
     */
    public function manage(User $user): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can manage compliance exports.
     */
    public function manageExports(User $user): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can anonymize patient data.
     */
    public function anonymize(User $user, Patient $patient): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can view export requests.
     */
    public function viewExports(User $user): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can download export files.
     */
    public function downloadExport(User $user, DataExportRequest $export): bool
    {
        return $user->role === 'Admin';
    }
}
