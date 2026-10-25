<?php

namespace App\Console\Commands;

use App\Jobs\ConvertDocumentImport;
use App\Jobs\DeliverDocumentEmail;
use App\Jobs\DeliverRealtimeNotification;
use App\Jobs\FinalizeDocument;
use App\Jobs\GenerateDocumentPreview;
use App\Jobs\ProcessDocumentOcr;
use App\Models\DocumentOcrPage;
use App\Models\DocumentVersion;
use App\Models\EmailNotification;
use App\Models\RealtimeDelivery;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessDocumentWork extends Command
{
    protected $signature = 'documents:process';

    protected $description = 'Dispatch durable pending preview, finalization, and notifications';

    public function handle(): int
    {
        $startedAt = hrtime(true);
        Log::info('Document work dispatch started.');

        try {
            $this->dispatchPendingWork();
        } catch (Throwable $exception) {
            Log::error('Document work dispatch failed.', [
                'elapsed_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                'exit_code' => self::FAILURE,
                'error' => class_basename($exception),
            ]);
            $this->error('Document work dispatch failed. See application logs.');

            return self::FAILURE;
        }

        Log::info('Document work dispatch completed.', [
            'elapsed_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            'exit_code' => self::SUCCESS,
        ]);
        $this->info('Document work dispatch completed.');

        return self::SUCCESS;
    }

    private function dispatchPendingWork(): void
    {
        DocumentVersion::where('status', 'draft')->where('conversion_status', 'queued')->whereNotNull('conversion_generation')
            ->chunkById(100, function (Collection $versions): void {
                foreach ($versions as $version) {
                    ConvertDocumentImport::dispatch($version->guid, $version->conversion_generation);
                }
            });
        DocumentVersion::where('status', 'draft')->where('preview_status', 'queued')->whereNotNull('preview_generation')
            ->chunkById(100, function (Collection $versions): void {
                foreach ($versions as $version) {
                    GenerateDocumentPreview::dispatch($version->guid, $version->preview_generation);
                }
            });
        DocumentVersion::where('status', 'finalizing')->whereNull('finalization_error_code')
            ->chunkById(100, function (Collection $versions): void {
                foreach ($versions as $version) {
                    FinalizeDocument::dispatch($version->guid);
                }
            });
        EmailNotification::where('status', 'queued')->where('attempt_count', '<', 3)
            ->where(fn (Builder $query): Builder => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->chunkById(100, function (Collection $notifications): void {
                foreach ($notifications as $notification) {
                    DeliverDocumentEmail::dispatch($notification->guid);
                }
            });
        RealtimeDelivery::where('status', 'queued')->where('attempt_count', '<', 3)
            ->where(fn (Builder $query): Builder => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->chunkById(100, function (Collection $deliveries): void {
                foreach ($deliveries as $delivery) {
                    DeliverRealtimeNotification::dispatch($delivery->guid);
                }
            });
        DocumentOcrPage::where('status', 'queued')->distinct()->get(['version_guid', 'pdf_hash'])
            ->each(fn (DocumentOcrPage $page) => ProcessDocumentOcr::dispatch($page->version_guid, $page->pdf_hash));
    }
}
