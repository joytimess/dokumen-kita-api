<?php

namespace App\Jobs;

use App\Services\RealtimeNotificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverRealtimeNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public int $uniqueFor = 300;

    public function __construct(public string $deliveryGuid)
    {
        $this->onConnection(config('documents.processing.connection'));
        $this->onQueue(config('documents.processing.queue'));
    }

    public function uniqueId(): string
    {
        return $this->deliveryGuid;
    }

    public function handle(RealtimeNotificationService $notifications): void
    {
        $notifications->deliver($this->deliveryGuid);
    }
}
