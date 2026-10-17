<?php

namespace App\Jobs;

use App\Models\DocumentOcrPage;
use App\Services\DocumentOcrService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessDocumentOcr implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public string $versionGuid, public string $pdfHash)
    {
        $this->onConnection(config('documents.processing.connection'));
        $this->onQueue(config('documents.processing.queue'));
    }

    public function uniqueId(): string
    {
        return $this->versionGuid.':'.$this->pdfHash;
    }

    public function handle(DocumentOcrService $ocr): void
    {
        $ocr->process($this->versionGuid, $this->pdfHash);
    }

    public function failed(?Throwable $exception): void
    {
        DocumentOcrPage::where('version_guid', $this->versionGuid)->where('pdf_hash', $this->pdfHash)
            ->where('status', 'processing')->update([
                'status' => 'failed', 'error_code' => 'OCR_SERVICE_UNAVAILABLE', 'retryable' => true,
                'processed_at' => now(), 'updated_at' => now(),
            ]);
    }
}
