<?php

namespace App\Jobs;

use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use App\Services\DocumentPipelineService;
use App\Services\PdfDocumentRenderer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateDocumentPreview implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public string $versionGuid, public string $generation)
    {
        $this->onConnection(config('documents.processing.connection'));
        $this->onQueue(config('documents.processing.queue'));
    }

    public function uniqueId(): string
    {
        return $this->versionGuid.':'.$this->generation;
    }

    public function handle(DocumentPipelineService $pipeline, PdfDocumentRenderer $renderer): void
    {
        try {
            $pipeline->generatePreview($this->versionGuid, $this->generation, $renderer);
        } catch (ApiException $exception) {
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DocumentVersion::where('guid', $this->versionGuid)->where('status', 'draft')->where('preview_status', 'queued')->where('preview_generation', $this->generation)->update(['preview_status' => 'failed', 'preview_error_code' => $exception instanceof ApiException ? $exception->errorCode : 'PREVIEW_FAILED']);
    }
}
