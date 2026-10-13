<?php

namespace App\Services;

use App\Enums\VersionStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use App\Models\SignaturePlaceholder;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PublishService
{
    public function __construct(
        private readonly VersionWorkflow $workflow,
        private readonly StoredPreviewSnapshot $preview,
        private readonly InvitationService $invitations,
        private readonly AuditRecorder $audit,
    ) {}

    public function publish(User $actor, DocumentVersion $version): DocumentVersion
    {
        return DB::transaction(function () use ($actor, $version): DocumentVersion {
            $locked = $this->workflow->lock($version);
            $this->workflow->assertCurrent($locked);

            if ($locked->published_at !== null && in_array($locked->status, [
                VersionStatus::InProgress, VersionStatus::Finalizing, VersionStatus::Completed,
            ], true)) {
                return $locked->load('assignments.placeholders');
            }

            $this->workflow->assertDraft($locked);
            if ($locked->document->versions()->where('guid', '!=', $locked->guid)
                ->whereIn('status', ['preparing', 'in_progress', 'finalizing', 'revision_requested'])->exists()) {
                throw ApiException::conflict('ACTIVE_VERSION_EXISTS', 'Dokumen masih memiliki versi aktif.');
            }

            $assignments = $locked->assignments()->orderBy('sequence_number')->lockForUpdate()->get();
            if ($assignments->isEmpty()
                || $assignments->pluck('sequence_number')->all() !== range(1, $assignments->count())
                || $assignments->pluck('normalized_recipient_email')->unique()->count() !== $assignments->count()) {
                throw new ApiException(422, 'SIGNERS_INVALID', 'Daftar penerima harus memiliki urutan 1 sampai N tanpa duplikasi.');
            }

            $this->preview->verify($locked);
            $assignments->load('placeholders');
            foreach ($assignments as $assignment) {
                if ($assignment->placeholders->isEmpty()) {
                    throw new ApiException(422, 'PLACEHOLDERS_REQUIRED', 'Setiap penerima harus memiliki placeholder tanda tangan.');
                }
                foreach ($assignment->placeholders as $placeholder) {
                    if ($placeholder->needs_review) {
                        throw ApiException::conflict('PLACEHOLDERS_NEED_REVIEW', 'Tinjau ulang placeholder setelah perubahan konten.');
                    }
                    if (! $this->validPlaceholder($placeholder, $locked->preview_page_count)) {
                        throw new ApiException(422, 'PLACEHOLDERS_INVALID', 'Placeholder tidak sesuai dengan halaman preview.');
                    }
                }
            }

            $locked->update([
                'manifest_hash' => $this->manifestHash($locked, $assignments),
                'status' => VersionStatus::InProgress,
                'published_at' => now(),
            ]);
            $locked->document->update(['status' => VersionStatus::InProgress]);

            foreach ($assignments as $assignment) {
                $assignment->update(['status' => $assignment->sequence_number === 1 ? 'ready' : 'waiting']);
                $this->invitations->issue($assignment);
            }

            $this->audit->record('version.published', $locked->document, $locked, $actor, [
                'content_hash' => $locked->content_hash,
                'manifest_hash' => $locked->manifest_hash,
                'signer_count' => $assignments->count(),
            ]);

            return $locked->setRelation('assignments', $assignments);
        });
    }

    private function validPlaceholder(SignaturePlaceholder $placeholder, int $pages): bool
    {
        return $placeholder->page_number >= 1 && $placeholder->page_number <= $pages
            && $placeholder->x >= 0 && $placeholder->y >= 0
            && $placeholder->width > 0 && $placeholder->height > 0
            && round(($placeholder->x + $placeholder->width) * 1_000_000) <= 1_000_000
            && round(($placeholder->y + $placeholder->height) * 1_000_000) <= 1_000_000;
    }

    /**
     * Canonical manifest v1: fixed key order, signers ordered by sequence, placeholders
     * sorted lexicographically by [page,x,y,width,height]. Coordinates are integer
     * millionths to match DECIMAL(8,6). Mutable claim/status fields are excluded.
     * Hash UTF-8 JSON without whitespace using SHA-256.
     *
     * @param  Collection<int, SignerAssignment>  $assignments
     */
    private function manifestHash(DocumentVersion $version, Collection $assignments): string
    {
        $signers = [];
        foreach ($assignments as $assignment) {
            $placeholders = $assignment->placeholders->map(fn (SignaturePlaceholder $placeholder): array => [
                $placeholder->page_number,
                (int) round($placeholder->x * 1_000_000),
                (int) round($placeholder->y * 1_000_000),
                (int) round($placeholder->width * 1_000_000),
                (int) round($placeholder->height * 1_000_000),
            ])->all();
            sort($placeholders);
            $signers[] = [
                'assignment_guid' => $assignment->guid,
                'email' => $assignment->normalized_recipient_email,
                'sequence_number' => $assignment->sequence_number,
                'placeholders' => $placeholders,
            ];
        }

        return hash('sha256', json_encode([
            'schema' => 1,
            'version_guid' => $version->guid,
            'content_hash' => $version->content_hash,
            'page_count' => $version->preview_page_count,
            'signers' => $signers,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
