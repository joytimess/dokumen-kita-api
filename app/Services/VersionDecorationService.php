<?php

namespace App\Services;

use App\Enums\PreviewStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use App\Models\SignaturePlaceholder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class VersionDecorationService
{
    public function __construct(
        private readonly VersionWorkflow $workflow,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed>|null $pages */
    public function update(User $actor, DocumentVersion $version, string $kind, int $revision, ?UploadedFile $file, ?array $pages): DocumentVersion
    {
        $normalizedPages = $pages === null ? null : $this->pages($pages);
        $newImage = $file === null ? null : $this->storeImage($version, $file);

        try {
            return DB::transaction(function () use ($actor, $version, $kind, $revision, $newImage, $normalizedPages): DocumentVersion {
                $locked = $this->workflow->lock($version);
                $this->workflow->assertDraft($locked);
                $this->workflow->assertCurrent($locked);
                $this->assertRevision($locked, $revision);

                $current = $locked->{$kind};
                if ($current === null && $newImage === null) {
                    throw ValidationException::withMessages(['file' => ['Unggah gambar sebelum mengatur cakupan halaman.']]);
                }
                $updated = $newImage ?? $current;
                $updated['pages'] = $normalizedPages ?? $current['pages'] ?? ['mode' => 'all'];
                if ($updated !== $current) {
                    $locked->{$kind} = $updated;
                    $this->invalidate($locked);
                    $locked->save();
                    $this->audit->record('version.updated', $locked->document, $locked, $actor, ['fields' => [$kind]]);
                }

                return $locked;
            });
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                Storage::disk(config('documents.disk'))->delete($newImage['path']);
            }

            throw $exception;
        }
    }

    public function delete(User $actor, DocumentVersion $version, string $kind, int $revision): DocumentVersion
    {
        return DB::transaction(function () use ($actor, $version, $kind, $revision): DocumentVersion {
            $locked = $this->workflow->lock($version);
            $this->workflow->assertDraft($locked);
            $this->workflow->assertCurrent($locked);
            $this->assertRevision($locked, $revision);
            if ($locked->{$kind} !== null) {
                $locked->{$kind} = null;
                $this->invalidate($locked);
                $locked->save();
                $this->audit->record('version.updated', $locked->document, $locked, $actor, ['fields' => [$kind]]);
            }

            return $locked;
        });
    }

    /** @return array{path: string, mime: string, width: int, height: int} */
    private function storeImage(DocumentVersion $version, UploadedFile $file): array
    {
        $path = $file->getPathname();
        $bytes = $file->isValid() && $path !== '' && is_file($path) && is_readable($path)
            ? @file_get_contents($path) : false;
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
            || $info[0] > config('documents.page_decorations.max_width')
            || $info[1] > config('documents.page_decorations.max_height')) {
            throw ValidationException::withMessages(['file' => ['Gambar harus PNG, JPEG, atau WebP yang dapat dibaca, maksimal 1200 x 400 piksel.']]);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ValidationException::withMessages(['file' => ['Gambar tidak dapat dibaca.']]);
        }
        try {
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            ob_start();
            try {
                imagepng($image);
                $normalized = ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($image);
        }
        $path = "documents/{$version->document_guid}/decorations/".Str::random(40).'.png';
        if (! Storage::disk(config('documents.disk'))->put($path, $normalized, ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to store page decoration.');
        }

        return ['path' => $path, 'mime' => 'image/png', 'width' => $info[0], 'height' => $info[1]];
    }

    /** @param array<string, mixed> $pages
     * @return array<string, mixed>
     */
    private function pages(array $pages): array
    {
        if (($pages['mode'] ?? null) === 'all' && ! isset($pages['ranges'])) {
            return ['mode' => 'all'];
        }
        if (($pages['mode'] ?? null) !== 'range' || ! is_array($pages['ranges'] ?? null)
            || ! array_is_list($pages['ranges']) || $pages['ranges'] === []
            || count($pages['ranges']) > (int) config('documents.processing.max_pages')) {
            throw ValidationException::withMessages(['pages' => ['Cakupan halaman harus all atau daftar rentang yang valid.']]);
        }
        $ranges = [];
        foreach ($pages['ranges'] as $index => $range) {
            if (! is_array($range) || array_values($range) !== $range || count($range) !== 2
                || ! is_int($range[0]) || ! is_int($range[1]) || $range[0] < 1 || $range[1] < $range[0]) {
                throw ValidationException::withMessages(["pages.ranges.$index" => ['Rentang halaman harus [mulai, akhir] dengan nomor mulai dari 1.']]);
            }
            $ranges[] = $range;
        }
        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        foreach ($ranges as $index => $range) {
            if ($index > 0 && $range[0] <= $ranges[$index - 1][1]) {
                throw ValidationException::withMessages(['pages.ranges' => ['Rentang halaman tidak boleh tumpang tindih atau duplikat.']]);
            }
        }

        return ['mode' => 'range', 'ranges' => $ranges];
    }

    private function assertRevision(DocumentVersion $version, int $revision): void
    {
        if ($revision !== $version->editor_revision) {
            throw ApiException::conflict('EDITOR_REVISION_CONFLICT', 'Draft berubah. Muat ulang editor sebelum menyimpan.');
        }
    }

    private function invalidate(DocumentVersion $version): void
    {
        $version->editor_revision++;
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
}
