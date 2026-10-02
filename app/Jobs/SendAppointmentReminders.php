<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendAppointmentReminders implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $hours = 24) {}

    /**
     * Remind patients about appointments scheduled within the next window.
     */
    public function handle(NotificationService $service): void
    {
        $appointments = Appointment::query()
            ->whereIn('status', [Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED])
            ->whereBetween('scheduled_at', [now(), now()->addHours($this->hours)])
            ->with('patient')
            ->orderBy('scheduled_at')
            ->get();

        foreach ($appointments as $appointment) {
            /** @var User|null $user */
            $user = User::query()->where('patient_id', $appointment->patient_id)->first();

            if ($user === null) {
                Log::info("[reminders] No portal account for patient #{$appointment->patient_id}; no reminder sent.");

                continue;
            }

            $service->send(
                $user,
                'appointments',
                'Appointment Reminder',
                sprintf(
                    'This is a reminder that you have an appointment on %s at %s.%s',
                    $appointment->scheduled_at?->format('l, F jS') ?? 'your scheduled date',
                    $appointment->scheduled_at?->format('g:i A') ?? 'your scheduled time',
                    $appointment->reason ? ' Reason: '.$appointment->reason : '',
                ),
            );
        }
    }
}
