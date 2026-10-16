<?php

namespace App\Jobs;

use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use App\Services\DocumentPipelineService;
use App\Services\PdfDocumentRenderer;
use App\Services\RealtimeNotificationService;
use App\Services\VersionWorkflow;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public string $versionGuid)
    {
        $this->onConnection(config('documents.processing.connection'));
        $this->onQueue(config('documents.processing.queue'));
    }

    public function uniqueId(): string
    {
        return $this->versionGuid;
    }

    public function handle(DocumentPipelineService $pipeline, PdfDocumentRenderer $renderer): void
    {
        try {
            $pipeline->finalize($this->versionGuid, $renderer);
        } catch (ApiException $exception) {
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $version = DocumentVersion::where('guid', $this->versionGuid)->first();
        if ($version === null) {
            return;
        }

        DB::transaction(function () use ($version, $exception): void {
            $locked = app(VersionWorkflow::class)->lock($version);
            if ($locked->status->value !== 'finalizing') {
                return;
            }

            $locked->update(['finalization_error_code' => $exception instanceof ApiException ? $exception->errorCode : 'FINALIZATION_FAILED']);
            app(RealtimeNotificationService::class)->notifyOwner($locked, 'version.final_pdf_failed');
            Log::warning('Document finalization failed.', ['version_guid' => $locked->guid, 'error' => class_basename($exception)]);
        });
    }
}
