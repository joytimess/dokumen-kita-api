<?php

namespace App\Services;

use App\Enums\PreviewStatus;
use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\SignaturePlaceholder;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DocumentService
{
    public function __construct(
        private readonly EditorDataSanitizer $sanitizer,
        private readonly SourceFileInspector $inspector,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $editorData
     */
    public function createDraft(User $owner, string $title, ?array $editorData): Document
    {
        $clean = $editorData === null ? ['blocks' => []] : $this->sanitizer->sanitize($editorData);

        return DB::transaction(function () use ($owner, $title, $clean): Document {
            $document = Document::create([
                'owner_guid' => $owner->guid,
                'public_reference' => Str::random(32),
                'title' => $title,
                'status' => VersionStatus::Draft,
            ]);

            $version = $document->versions()->create([
                'version_number' => 1,
                'editor_data_json' => $clean,
                'editor_revision' => 1,
                'conversion_status' => 'none',
                'status' => VersionStatus::Draft,
                'preview_status' => PreviewStatus::None,
            ]);

            $this->audit->record('document.created', $document, $version, $owner);

            return $document->setRelation('latestVersion', $version);
        });
    }

    /**
     * @return LengthAwarePaginator<int, Document>
     */
    public function paginateForOwner(User $owner, ?string $status, int $perPage, ?string $search = null, ?string $categoryGuid = null, bool $uncategorized = false): LengthAwarePaginator
    {
        if ($categoryGuid !== null && ! DocumentCategory::where('owner_guid', $owner->guid)->where('guid', $categoryGuid)->exists()) {
            abort(404);
        }

        return Document::query()
            ->where('owner_guid', $owner->guid)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search !== null && $search !== '', function ($query) use ($search): void {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
                $query->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern]);
            })
            ->when($categoryGuid !== null, fn ($query) => $query->where('category_guid', $categoryGuid))
            ->when($uncategorized, fn ($query) => $query->whereNull('category_guid'))
            ->with('latestVersion.document', 'category')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{title?: string, editor_data?: array<string, mixed>, editor_revision?: int}  $changes
     */
    public function updateDraft(User $actor, DocumentVersion $version, array $changes): DocumentVersion
    {
        $clean = isset($changes['editor_data']) ? $this->sanitizer->sanitize($changes['editor_data']) : null;

        return DB::transaction(function () use ($actor, $version, $changes, $clean): DocumentVersion {
            $document = Document::where('guid', $version->document_guid)->lockForUpdate()->firstOrFail();
            $locked = DocumentVersion::whereKey($version->getKey())->lockForUpdate()->firstOrFail();

            $this->assertDraft($locked);
            $this->assertCurrent($document, $locked);

            if ($clean !== null && ($changes['editor_revision'] ?? null) !== $locked->editor_revision) {
                throw ApiException::conflict('EDITOR_REVISION_CONFLICT', 'Draft berubah. Muat ulang editor sebelum menyimpan.');
            }

            $changed = [];

            if (isset($changes['title']) && $changes['title'] !== $document->title) {
                $document->update(['title' => $changes['title']]);
                $changed[] = 'title';
            }

            if ($clean !== null && $clean !== $locked->editor_data_json) {
                $locked->editor_data_json = $clean;
                $locked->editor_revision++;
                if (in_array($locked->conversion_status, ['queued', 'processing'], true)) {
                    $locked->conversion_status = 'failed';
                    $locked->conversion_error_code = 'DRAFT_CHANGED';
                    $locked->conversion_generation = null;
                }
                $this->invalidatePreview($locked);
                $locked->save();
                $changed[] = 'content';
            }

            if ($changed !== []) {
                $this->audit->record('version.updated', $document, $locked, $actor, ['fields' => $changed]);
            }

            return $locked;
        });
    }

    /**
     * Stores the uploaded PDF/DOCX as the private source of the document's current draft.
     */
    public function importSource(User $actor, Document $document, UploadedFile $file, bool $replaceEditorData = false, ?int $editorRevision = null): DocumentVersion
    {
        $type = $this->inspector->inspect($file);

        $disk = Storage::disk(config('documents.disk'));
        $newPath = $disk->putFileAs(
            "documents/{$document->guid}/sources",
            $file,
            Str::random(40).'.'.$type->value,
            ['visibility' => 'private'],
        );

        try {
            [$version, $oldPath] = DB::transaction(function () use ($actor, $document, $type, $newPath, $replaceEditorData, $editorRevision): array {
                $lockedDocument = Document::whereKey($document->getKey())->lockForUpdate()->firstOrFail();

                $version = DocumentVersion::where('document_guid', $document->guid)
                    ->orderByDesc('version_number')
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertDraft($version);
                $this->assertCurrent($lockedDocument, $version);
                if ($editorRevision !== null && $editorRevision !== $version->editor_revision) {
                    throw ApiException::conflict('EDITOR_REVISION_CONFLICT', 'Draft berubah. Muat ulang editor sebelum mengimpor.');
                }
                if (! $replaceEditorData && ($version->editor_data_json['blocks'] ?? []) !== []) {
                    throw ApiException::conflict('REPLACE_EDITOR_DATA_REQUIRED', 'Konfirmasi penggantian isi draft sebelum mengimpor berkas.');
                }

                $oldPath = $version->source_path;

                $version->source_type = $type;
                $version->source_path = $newPath;
                $version->conversion_status = 'queued';
                $version->conversion_generation = (string) Str::uuid();
                $version->conversion_error_code = null;
                $version->conversion_warnings = null;
                $version->conversion_page_count = null;
                $this->invalidatePreview($version);
                $version->save();

                $this->audit->record('version.source_imported', $document, $version, $actor, ['source_type' => $type->value]);

                return [$version, $oldPath];
            });
        } catch (Throwable $e) {
            $disk->delete($newPath);

            throw $e;
        }

        if ($oldPath !== null && $oldPath !== $newPath && ! DocumentVersion::where('source_path', $oldPath)->exists()) {
            $disk->delete($oldPath);
        }

        return $version;
    }

    /**
     * Content changes make the rendered preview and any placed signatures unreviewed.
     */
    private function invalidatePreview(DocumentVersion $version): void
    {
        $version->preview_generation = null;
        $version->preview_error_code = null;
        $version->preview_error_message = null;
        if ($version->preview_status !== PreviewStatus::None) {
            $version->preview_status = PreviewStatus::Stale;
        }

        $version->content_hash = null;
        $version->preview_page_count = null;

        SignaturePlaceholder::query()
            ->whereIn('assignment_guid', $version->assignments()->select('guid'))
            ->update(['needs_review' => true]);
    }

    private function assertDraft(DocumentVersion $version): void
    {
        if ($version->status !== VersionStatus::Draft) {
            throw ApiException::conflict('VERSION_NOT_EDITABLE', 'Versi ini sudah tidak dapat diubah. Buat revisi untuk mengubah dokumen.');
        }
    }

    private function assertCurrent(Document $document, DocumentVersion $version): void
    {
        if ($document->versions()->where('version_number', '>', $version->version_number)->lockForUpdate()->exists()) {
            throw ApiException::conflict('VERSION_CONFLICT', 'Versi ini bukan versi dokumen terkini.');
        }
    }
}
