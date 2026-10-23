<?php

namespace App\Services;

use App\Enums\PreviewStatus;
use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Jobs\GenerateDocumentPreview;
use App\Models\DocumentVersion;
use App\Models\EmailNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentPipelineService
{
    public function __construct(
        private readonly VersionWorkflow $workflow,
        private readonly AuditRecorder $audit,
        private readonly StoredPreviewSnapshot $snapshot,
        private readonly RealtimeNotificationService $realtime,
    ) {}

    public function requestPreview(User $actor, DocumentVersion $version, string $source): DocumentVersion
    {
        $dispatch = null;

        $result = DB::transaction(function () use ($actor, $version, $source, &$dispatch): DocumentVersion {
            $locked = $this->workflow->lock($version);
            $this->workflow->assertDraft($locked);
            $this->workflow->assertCurrent($locked);

            if ($locked->conversion_status !== 'none' && $locked->conversion_status !== 'ready') {
                throw ApiException::conflict('CONVERSION_NOT_READY', 'Tunggu hasil konversi atau perbaiki kegagalannya sebelum membuat preview.');
            }

            if ($source === 'upload' && $locked->conversion_status !== 'none') {
                throw ApiException::conflict('EDITOR_PREVIEW_REQUIRED', 'Preview impor yang dapat diedit harus bersumber dari editor.');
            }

            if ($source === 'upload' && ! $locked->source_path) {
                throw new ApiException(422, 'SOURCE_REQUIRED', 'Impor berkas sebelum membuat preview dari upload.');
            }

            if ($source === 'upload' && ($locked->letterhead !== null || $locked->footer !== null)) {
                throw new ApiException(422, 'EDITOR_PREVIEW_REQUIRED', 'Kop atau footer memerlukan preview dari editor agar isi tidak tertimpa.');
            }

            if ($locked->preview_status === PreviewStatus::Queued
                && $locked->preview_source === $source
                && $locked->preview_generation !== null) {
                return $locked;
            }

            $generation = (string) Str::uuid();

            $locked->update([
                'preview_generation' => $generation,
                'preview_source' => $source,
                'preview_status' => PreviewStatus::Queued,
                'preview_error_code' => null,
                'preview_error_message' => null,
                'content_hash' => null,
                'preview_page_count' => null,
            ]);

            foreach ($locked->assignments()->get() as $assignment) {
                $assignment->placeholders()->update(['needs_review' => true]);
            }

            $this->audit->record('version.preview_requested', $locked->document, $locked, $actor, ['source' => $source]);

            $dispatch = [
                'guid' => $locked->guid,
                'generation' => $generation,
            ];

            return $locked;
        });

        if ($dispatch !== null) {
            GenerateDocumentPreview::dispatch($dispatch['guid'], $dispatch['generation'])
                ->afterCommit()
                ->onQueue('documents');
        }

        return $result;
    }

    public function generatePreview(string $guid, string $generation, PdfDocumentRenderer $renderer): void
    {
        $version = DocumentVersion::where('guid', $guid)->firstOrFail();
        if (! $this->isPendingPreview($version, $generation)) {
            return;
        }
        $rendered = $renderer->preview($version);
        $disk = Storage::disk(config('documents.disk'));
        $path = "documents/{$version->document_guid}/previews/".Str::uuid().'.pdf';
        if (! $disk->put($path, $rendered['bytes'], ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to store preview.');
        }
        $kept = false;
        try {
            $kept = DB::transaction(function () use ($version, $generation, $rendered, $path): bool {
                $locked = $this->workflow->lock($version);
                if (! $this->isPendingPreview($locked, $generation)) {
                    return false;
                }
                $this->workflow->assertCurrent($locked);
                $locked->update([
                    'preview_status' => PreviewStatus::Ready,
                    'preview_pdf_path' => $path,
                    'preview_page_count' => $rendered['pages'],
                    'content_hash' => hash('sha256', $rendered['bytes']),
                    'preview_error_code' => null,
                ]);
                $this->audit->record('version.preview_ready', $locked->document, $locked, null);

                return true;
            });
        } finally {
            if (! $kept) {
                $disk->delete($path);
            }
        }
    }

    public function retryFinalization(User $actor, DocumentVersion $version): DocumentVersion
    {
        return DB::transaction(function () use ($actor, $version): DocumentVersion {
            $locked = $this->workflow->lock($version);
            $this->workflow->assertCurrent($locked);
            if ($locked->status !== VersionStatus::Finalizing) {
                throw ApiException::conflict('VERSION_NOT_FINALIZING', 'Versi belum menunggu finalisasi.');
            }
            if ($locked->finalization_error_code !== null) {
                $locked->update(['finalization_error_code' => null]);
                $this->audit->record('version.finalization_retried', $locked->document, $locked, $actor);
            }

            return $locked;
        });
    }

    public function finalize(string $guid, PdfDocumentRenderer $renderer): void
    {
        $version = DocumentVersion::where('guid', $guid)->firstOrFail();
        if ($version->status !== VersionStatus::Finalizing || $version->finalization_error_code !== null) {
            return;
        }
        $this->snapshot->verify($version);
        $version->load('assignments.signature.asset', 'assignments.placeholders');
        $bytes = $renderer->finalize($version);
        $disk = Storage::disk(config('documents.disk'));
        $path = "documents/{$version->document_guid}/final/".Str::uuid().'.pdf';
        if (! $disk->put($path, $bytes, ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to store final PDF.');
        }
        $kept = false;
        try {
            $kept = DB::transaction(function () use ($version, $path, $bytes): bool {
                $locked = $this->workflow->lock($version);
                if ($locked->status !== VersionStatus::Finalizing || $locked->finalization_error_code !== null) {
                    return false;
                }
                $this->workflow->assertCurrent($locked);
                $this->snapshot->verify($locked);
                if ($locked->content_hash !== $version->content_hash || $locked->manifest_hash !== $version->manifest_hash) {
                    throw ApiException::conflict('FINALIZATION_SNAPSHOT_CHANGED', 'Snapshot finalisasi berubah.');
                }
                $this->snapshot->verifyFile($path, hash('sha256', $bytes), 'PDF');
                $this->completeFinalization($locked, $path, $bytes);

                return true;
            });
        } finally {
            if (! $kept) {
                $disk->delete($path);
            }
        }
    }

    private function completeFinalization(DocumentVersion $version, string $path, string $bytes): void
    {
        $version->update([
            'final_pdf_path' => $path, 'final_pdf_hash' => hash('sha256', $bytes),
            'status' => VersionStatus::Completed, 'finalization_error_code' => null,
        ]);
        $version->document->update(['status' => VersionStatus::Completed]);
        $ownerEmail = Str::lower(trim($version->document->owner->email));
        $recipients = $version->assignments()->pluck('recipient_email')->prepend($ownerEmail)
            ->map(fn (string $email): string => Str::lower(trim($email)))->unique();
        foreach ($recipients as $email) {
            // Preserve the existing owner key so retries cannot duplicate older completion rows.
            $key = 'completed:'.$version->guid.($email === $ownerEmail ? '' : ':'.hash('sha256', $email));
            EmailNotification::firstOrCreate(['deduplication_key' => $key], [
                'version_guid' => $version->guid, 'recipient_email' => $email,
                'notification_type' => 'version_completed', 'status' => 'queued',
                'payload' => ['completed_at' => now()->toIso8601String()],
            ]);
        }
        $this->audit->record('version.completed', $version->document, $version, null, ['final_pdf_hash' => $version->final_pdf_hash]);
        $this->realtime->notifyOwner($version, 'version.final_pdf_ready');
        foreach ($version->assignments()->with('user')->get() as $assignment) {
            if ($assignment->user_guid !== null && $assignment->user_guid !== $version->document->owner_guid) {
                $this->realtime->notifyAssignment($version, $assignment, 'version.final_pdf_ready');
            }
        }
    }

    private function isPendingPreview(DocumentVersion $version, string $generation): bool
    {
        return $version->status === VersionStatus::Draft
            && $version->preview_status === PreviewStatus::Queued
            && $version->preview_generation === $generation;
    }
}
