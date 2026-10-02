<?php

namespace App\Providers;

use App\Broadcasting\AuthorizableLogBroadcaster;
use App\Listeners\RecordCacheMetrics;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Claim;
use App\Models\ClinicalDecisionSupport;
use App\Models\DataExportRequest;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\Followup;
use App\Models\InventoryItem;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\SystemBackup;
use App\Models\SystemSetting;
use App\Observers\PatientObserver;
use App\Policies\BackupPolicy;
use App\Policies\CdssPolicy;
use App\Policies\ClaimPolicy;
use App\Policies\FollowupPolicy;
use App\Policies\LeadPolicy;
use App\Policies\SystemAdminPolicy;
use App\Services\QueryCacheService;
use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The cache hit/miss counters that MetricsController exports had no
        // writer anywhere in the codebase, so both stayed at zero and the
        // cache-hit-ratio alert evaluated 0/0 (NaN) and could never fire.
        //
        // Recorded explicitly as well as being auto-discovered, so the wiring is
        // visible at the point the rest of the monitoring metrics are set up
        // rather than depending on a directory convention alone.
        Event::listen(CacheEvent::class, RecordCacheMetrics::class);

        Gate::policy(ClinicalDecisionSupport::class, CdssPolicy::class);
        Gate::policy(SystemSetting::class, SystemAdminPolicy::class);
        Gate::policy(Patient::class, SystemAdminPolicy::class);
        Gate::policy(DataExportRequest::class, SystemAdminPolicy::class);
        Gate::policy(SystemBackup::class, BackupPolicy::class);
        Gate::policy(Claim::class, ClaimPolicy::class);
        Gate::policy(Followup::class, FollowupPolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);

        Patient::observe(PatientObserver::class);

        $this->registerCacheInvalidation();

        RateLimiter::for('api', static function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', static function (Request $request): Limit {
            return Limit::perMinute((int) env('AUTH_RATE_LIMIT_PER_MINUTE', 5))->by($request->ip());
        });

        RateLimiter::for('sensitive', static function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('public', static function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('public-submit', static function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });

        if ($this->app->environment('testing')) {
            Broadcast::extend('log', static fn ($app): AuthorizableLogBroadcaster => new AuthorizableLogBroadcaster(
                $app->make(LoggerInterface::class),
            ));
        }
    }

    /**
     * Invalidate cached query groups when the underlying records mutate.
     */
    private function registerCacheInvalidation(): void
    {
        $cache = $this->app->make(QueryCacheService::class);

        $groups = [
            Branch::class => 'branches',
            Appointment::class => 'appointments.availability',
            DoctorSchedule::class => 'appointments.availability',
            DoctorTimeOff::class => 'appointments.availability',
            InventoryItem::class => 'inventory.low-stock',
            SystemSetting::class => 'system_settings',
        ];

        foreach ($groups as $model => $group) {
            $model::saved(static fn () => $cache->forgetGroup($group));
            $model::deleted(static fn () => $cache->forgetGroup($group));
        }
    }
}
