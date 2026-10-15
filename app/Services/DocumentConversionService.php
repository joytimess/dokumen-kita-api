<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use App\Models\SignaturePlaceholder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentConversionService
{
    public function __construct(
        private readonly VersionWorkflow $workflow,
        private readonly SourceDocumentConverter $converter,
        private readonly AuditRecorder $audit,
    ) {}

    public function retry(User $actor, DocumentVersion $version, bool $replaceEditorData = false, ?int $editorRevision = null): DocumentVersion
    {
        return DB::transaction(function () use ($actor, $version, $replaceEditorData, $editorRevision): DocumentVersion {
            $locked = $this->workflow->lock($version);
            $this->workflow->assertDraft($locked);
            $this->workflow->assertCurrent($locked);
            if ($locked->source_path === null) {
                throw new ApiException(422, 'SOURCE_REQUIRED', 'Impor berkas sebelum mengulangi konversi.');
            }
            if (in_array($locked->conversion_status, ['queued', 'processing', 'ready'], true)) {
                return $locked;
            }
            if ($editorRevision !== null && $editorRevision !== $locked->editor_revision) {
                throw ApiException::conflict('EDITOR_REVISION_CONFLICT', 'Draft berubah. Muat ulang editor sebelum mencoba ulang.');
            }
            if (! $replaceEditorData && ($locked->editor_data_json['blocks'] ?? []) !== []) {
                throw ApiException::conflict('REPLACE_EDITOR_DATA_REQUIRED', 'Konfirmasi penggantian isi draft sebelum mengulangi konversi.');
            }

            $locked->update([
                'conversion_status' => 'queued',
                'conversion_generation' => (string) Str::uuid(),
                'conversion_error_code' => null,
                'conversion_warnings' => null,
                'conversion_page_count' => null,
            ]);
            $this->audit->record('version.conversion_retried', $locked->document, $locked, $actor);

            return $locked;
        });
    }

    public function convert(string $versionGuid, string $generation): void
    {
        $pending = DB::transaction(function () use ($versionGuid, $generation): ?array {
            $candidate = DocumentVersion::where('guid', $versionGuid)->firstOrFail();
            $version = $this->workflow->lock($candidate);
            if ($version->status !== VersionStatus::Draft || $version->conversion_generation !== $generation
                || ! in_array($version->conversion_status, ['queued', 'processing'], true)) {
                return null;
            }
            $this->workflow->assertCurrent($version);
            if ($version->conversion_status === 'queued') {
                $version->update(['conversion_status' => 'processing']);
            }

            return [$version->source_type, $version->source_path, $version->editor_revision];
        });
        if ($pending === null) {
            return;
        }

        [$type, $path, $editorRevision] = $pending;
        $result = $this->converter->convert($type, $path);
        if (($result['editor_data']['blocks'] ?? []) === []) {
            throw new ApiException(422, 'SOURCE_NO_EDITABLE_CONTENT', 'Sumber tidak memiliki isi yang dapat diedit.');
        }

        DB::transaction(function () use ($versionGuid, $generation, $path, $editorRevision, $result): void {
            $candidate = DocumentVersion::where('guid', $versionGuid)->firstOrFail();
            $version = $this->workflow->lock($candidate);
            if ($version->status !== VersionStatus::Draft || $version->conversion_status !== 'processing'
                || $version->conversion_generation !== $generation || $version->source_path !== $path) {
                return;
            }
            $this->workflow->assertCurrent($version);
            if ($version->editor_revision !== $editorRevision) {
                $version->update(['conversion_status' => 'failed', 'conversion_error_code' => 'DRAFT_CHANGED', 'conversion_generation' => null]);

                return;
            }

            $version->update([
                'editor_data_json' => $result['editor_data'],
                'editor_revision' => $version->editor_revision + 1,
                'conversion_status' => 'ready',
                'conversion_error_code' => null,
                'conversion_warnings' => $result['warnings'],
                'conversion_page_count' => $result['pages'],
                'preview_generation' => null,
                'preview_error_code' => null,
                'preview_error_message' => null,
                'preview_status' => $version->preview_status->value === 'none' ? 'none' : 'stale',
                'content_hash' => null,
                'preview_page_count' => null,
            ]);
            SignaturePlaceholder::whereIn('assignment_guid', $version->assignments()->select('guid'))
                ->update(['needs_review' => true]);
            $this->audit->record('version.conversion_ready', $version->document, $version, null, [
                'page_count' => $result['pages'], 'block_count' => count($result['editor_data']['blocks']),
                'warnings' => $result['warnings'],
            ]);
        });
    }
}
