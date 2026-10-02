<?php

namespace App\Jobs;

use App\Models\Followup;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendFollowupReminders implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $hours = 24) {}

    /**
     * Surface follow-ups that are due (or already overdue) to their assigned
     * staff member through the shared notification pipeline so the duty to
     * action them is delivered on the same channels as appointment reminders.
     */
    public function handle(NotificationService $service): void
    {
        $followups = Followup::query()
            ->pending()
            ->where('due_at', '<=', now()->addHours($this->hours))
            ->with(['assignee', 'patient:id,name', 'lead:id,name'])
            ->get();

        foreach ($followups as $followup) {
            $assignee = $followup->assignee;

            if ($assignee === null) {
                Log::info("[followups] No assignee for follow-up #{$followup->id}; no reminder sent.");

                continue;
            }

            $overdue = $followup->due_at !== null && $followup->due_at->isPast();

            $service->send(
                $assignee,
                'followups',
                $overdue ? 'Overdue follow-up' : 'Due follow-up',
                $this->message($followup),
            );
        }
    }

    private function message(Followup $followup): string
    {
        $subject = $followup->patient?->name !== null
            ? "patient {$followup->patient->name}"
            : ($followup->lead?->name !== null ? "lead {$followup->lead->name}" : 'unknown subject');

        $due = $followup->due_at?->format('l, F jS g:i A') ?? 'scheduled time';

        return sprintf('A %s follow-up for %s is due on %s.', $followup->type, $subject, $due);
    }
}
