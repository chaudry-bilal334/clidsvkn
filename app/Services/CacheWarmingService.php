<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CacheWarmingService
{
    /**
     * Warm critical cache stores on deployment.
     *
     * Pre-loads frequently accessed data that would otherwise
     * cause database query delays on first request after deploy.
     */
    public function warmAll(): void
    {
        $this->warmRolePermissions();
        $this->warmLowStockCounts();
        $this->warmSystemConfiguration();
        $this->log('Cache warming completed');
    }

    /**
     * Warm role permissions cache.
     *
     * Pre-fetches all role-based permissions so that
     * permission checks are O(1) from cache instead of
     * database queries on every page load.
     */
    protected function warmRolePermissions(): void
    {
        $permissions = Cache::remember('permissions.all', 3600, function () {
            $allPermissions = [];

            // Collect permissions from all roles
            foreach (['Admin', 'Doctor', 'Receptionist', 'Accountant', 'Pharmacist'] as $role) {
                $rolePermissions = $this->getRolePermissions($role);
                $allPermissions = array_merge($allPermissions, $rolePermissions);
            }

            return array_unique($allPermissions);
        });

        // Also warm per-role caches
        foreach (['Admin', 'Doctor', 'Receptionist', 'Accountant', 'Pharmacist'] as $role) {
            Cache::remember('permissions.'.$role, 3600, function () use ($role) {
                return $this->getRolePermissions($role);
            });
        }
    }

    /**
     * Get permissions for a specific role.
     */
    protected function getRolePermissions(string $role): array
    {
        $roleMapping = [
            'Admin' => ['*'],
            'Doctor' => ['claims.view', 'cdss.view', 'cdss.manage', 'patients.view'],
            'Receptionist' => ['claims.view', 'claims.manage', 'appointments.manage'],
            'Accountant' => ['claims.view', 'claims.manage', 'invoices.view'],
            'Pharmacist' => ['pharmacy.view', 'pharmacy.manage', 'prescriptions.view'],
        ];

        return $roleMapping[$role] ?? [];
    }

    /**
     * Warm low-stock inventory counts cache.
     *
     * Pre-computes the count of inventory items that are
     * below their minimum stock level for quick dashboard display.
     */
    protected function warmLowStockCounts(): void
    {
        Cache::remember('inventory.low-stock-count', 300, function () {
            $count = 0;

            // Count inventory items below minimum stock
            // This assumes inventory_items table exists with stock and min_stock columns
            $lowStock = \DB::table('inventory_items')
                ->where('stock', '<', 'min_stock')
                ->count();

            return max(0, $lowStock);
        });
    }

    /**
     * Warm system configuration keys cache.
     *
     * Pre-loads all system settings into cache for
     * fast access without database queries.
     */
    protected function warmSystemConfiguration(): void
    {
        $keys = SystemSetting::pluck('value', 'key')->toArray();

        foreach ($keys as $key => $value) {
            Cache::put('system_setting.'.$key, $value, 86400);
        }
    }

    /**
     * Log cache warming actions.
     */
    protected function log(string $message): void
    {
        \Log::channel('audit')->info('Cache warming: '.$message);
    }
}
