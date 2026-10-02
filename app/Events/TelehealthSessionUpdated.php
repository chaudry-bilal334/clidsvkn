<?php

namespace App\Events;

use App\Models\TelehealthSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TelehealthSessionUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TelehealthSession $session,
        public readonly string $status,
        public readonly ?string $message = null,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('clinic.telehealth.'.$this->session->id),
        ];
    }

    /**
     * The event name broadcast over the wire (kept short for the SPA).
     */
    public function broadcastAs(): string
    {
        return 'TelehealthSessionUpdated';
    }

    /**
     * The payload delivered to connected clients.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'status' => $this->status,
            'patient_name' => $this->session->patient?->name,
            'doctor_name' => $this->session->doctor?->name,
            'message' => $this->message ?? sprintf('Telehealth session %s is now %s', $this->session->id, $this->status),
        ];
    }
}
