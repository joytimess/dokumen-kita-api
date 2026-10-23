<?php

namespace App\Jobs;

use App\Services\DocumentEmailService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverDocumentEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 90;

    public function __construct(public string $notificationGuid)
    {
        $this->afterCommit();
        $this->onConnection(config('documents.processing.connection'));
        $this->onQueue(config('documents.processing.queue'));
    }

    public function uniqueId(): string
    {
        return $this->notificationGuid;
    }

    public function handle(DocumentEmailService $emails): void
    {
        $emails->deliver($this->notificationGuid);
    }
}
