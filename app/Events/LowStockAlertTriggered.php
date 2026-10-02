<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LowStockAlertTriggered implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $item
     */
    public function __construct(
        public readonly array $item,
        public readonly int $currentStock,
        public readonly int $reorderLevel,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('clinic.roles.pharmacist'),
            new PrivateChannel('clinic.roles.admin'),
        ];
    }

    /**
     * The event name broadcast over the wire (kept short for the SPA).
     */
    public function broadcastAs(): string
    {
        return 'LowStockAlertTriggered';
    }

    /**
     * The payload delivered to connected clients.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $itemName = (string) ($this->item['name'] ?? 'Inventory item');

        return [
            'item' => $this->item,
            'current_stock' => $this->currentStock,
            'reorder_level' => $this->reorderLevel,
            'message' => sprintf(
                'Low stock: %s has %d units remaining (reorder level %d)',
                $itemName,
                $this->currentStock,
                $this->reorderLevel,
            ),
        ];
    }
}
