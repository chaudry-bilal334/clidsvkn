<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecurityEventLogged implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $event
     */
    public function __construct(
        public readonly array $event,
        public readonly string $type,
        public readonly ?string $user,
        public readonly ?string $ipAddress,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('clinic.roles.admin'),
        ];
    }

    /**
     * The event name broadcast over the wire (kept short for the SPA).
     */
    public function broadcastAs(): string
    {
        return 'SecurityEventLogged';
    }

    /**
     * The payload delivered to connected clients.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'event' => $this->event,
            'type' => $this->type,
            'user' => $this->user,
            'ip_address' => $this->ipAddress,
            'message' => sprintf(
                'Security alert (%s)%s',
                $this->type,
                $this->user ? " for {$this->user}" : '',
            ),
        ];
    }
}
