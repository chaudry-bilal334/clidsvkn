<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentStatusChanged implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $appointment
     */
    public function __construct(
        public readonly array $appointment,
        public readonly string $status,
        public readonly string $patientName,
        public readonly ?string $doctorName = null,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('clinic.roles.doctor'),
            new PrivateChannel('clinic.roles.receptionist'),
        ];
    }

    /**
     * The event name broadcast over the wire (kept short for the SPA).
     */
    public function broadcastAs(): string
    {
        return 'AppointmentStatusChanged';
    }

    /**
     * The payload delivered to connected clients.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'appointment' => $this->appointment,
            'status' => $this->status,
            'patient_name' => $this->patientName,
            'doctor_name' => $this->doctorName,
            'message' => sprintf(
                'Appointment %s for %s',
                $this->status,
                $this->patientName,
            ),
        ];
    }
}
