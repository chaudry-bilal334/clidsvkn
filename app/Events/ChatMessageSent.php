<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $sessionId,
        public readonly int $senderId,
        public readonly string $senderName,
        public readonly string $message,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('clinic.telehealth.'.$this->sessionId),
        ];
    }

    /**
     * The event name broadcast over the wire (kept short for the SPA).
     */
    public function broadcastAs(): string
    {
        return 'ChatMessageSent';
    }

    /**
     * The payload delivered to connected clients.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'sender_id' => $this->senderId,
            'sender_name' => $this->senderName,
            'message' => $this->message,
        ];
    }
}
