<?php

namespace App\Policies;

use App\Models\SystemBackup;
use App\Models\User;

class BackupPolicy
{
    /**
     * Determine whether the user can view any system backups.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can view a system backup.
     */
    public function view(User $user, SystemBackup $systemBackup): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can generate a system backup.
     */
    public function generate(User $user): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can download a backup file.
     */
    public function download(User $user, SystemBackup $systemBackup): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can delete a system backup.
     */
    public function delete(User $user, SystemBackup $systemBackup): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can manage system backups (alias for CRUD operations).
     */
    public function manage(User $user): bool
    {
        return $user->role === 'Admin';
    }

    /**
     * Determine whether the user can warm the cache.
     */
    public function warmCache(User $user): bool
    {
        return $user->role === 'Admin';
    }
}
