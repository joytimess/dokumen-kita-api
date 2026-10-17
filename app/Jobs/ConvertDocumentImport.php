<?php

namespace App\Jobs;

use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use App\Services\DocumentConversionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use Throwable;

class ConvertDocumentImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

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

    public function handle(DocumentConversionService $conversion): void
    {
        try {
            $conversion->convert($this->versionGuid, $this->generation);
        } catch (ValidationException) {
            $this->fail(new ApiException(422, 'CONVERSION_LIMIT', 'Hasil konversi melewati batas editor.'));
        } catch (ApiException $exception) {
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DocumentVersion::where('guid', $this->versionGuid)->where('status', 'draft')
            ->where('conversion_generation', $this->generation)
            ->whereIn('conversion_status', ['queued', 'processing'])
            ->update([
                'conversion_status' => 'failed',
                'conversion_error_code' => $exception instanceof ApiException ? $exception->errorCode : 'CONVERSION_FAILED',
            ]);
    }
}
