<?php

namespace App\Console\Commands;

use App\Jobs\DeliverDocumentEmail;
use App\Jobs\FinalizeDocument;
use App\Jobs\GenerateDocumentPreview;
use App\Models\DocumentVersion;
use App\Models\EmailNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProcessDocumentWork extends Command
{
    protected $signature = 'documents:process';

    protected $description = 'Dispatch durable pending preview, finalization, and email work';

    public function handle(): int
    {
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

        return self::SUCCESS;
    }
}
