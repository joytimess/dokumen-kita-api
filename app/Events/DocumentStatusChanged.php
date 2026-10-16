<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class DocumentStatusChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    /** @param array<string, string|null> $payload */
    public function __construct(public string $recipientGuid, public array $payload) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('users.'.$this->recipientGuid);
    }

    public function broadcastAs(): string
    {
        return 'document.status.changed';
    }

    /** @return array<string, string|null> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
