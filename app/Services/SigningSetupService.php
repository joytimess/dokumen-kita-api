<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SigningSetupService
{
    public function __construct(
        private readonly VersionWorkflow $workflow,
        private readonly StoredPreviewSnapshot $preview,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param list<array{email: string, sequence_number: int}> $signers */
    public function updateSigners(User $actor, DocumentVersion $version, array $signers, ?string $signingMode = null): DocumentVersion
    {
        return DB::transaction(function () use ($actor, $version, $signers, $signingMode): DocumentVersion {
            $locked = $this->workflow->lock($version);
            $this->workflow->assertDraft($locked);
            $this->workflow->assertCurrent($locked);
            if ($locked->published_at !== null) {
                throw ApiException::conflict('VERSION_NOT_EDITABLE', 'Versi yang sudah dipublikasikan tidak dapat diubah.');
            }
            if ($signingMode !== null) {
                $locked->update(['signing_mode' => $signingMode]);
            }
            $existing = $locked->assignments()->orderBy('sequence_number')->lockForUpdate()->get();

            $emails = array_column($signers, 'email');
            foreach ($existing as $assignment) {
                if (! in_array($assignment->normalized_recipient_email, $emails, true)) {
                    $assignment->placeholders()->delete();
                    $assignment->delete();
                }
            }

            $retained = $existing->whereIn('normalized_recipient_email', $emails)->keyBy('normalized_recipient_email');
            $offset = max((int) $existing->max('sequence_number'), count($signers)) + 1;
            foreach ($retained->values() as $index => $assignment) {
                $assignment->update(['sequence_number' => $offset + $index]);
            }

            foreach ($signers as $signer) {
                $assignment = $retained->get($signer['email']);
                if ($assignment === null) {
                    $locked->assignments()->create([
                        'recipient_email' => $signer['email'],
                        'normalized_recipient_email' => $signer['email'],
                        'sequence_number' => $signer['sequence_number'],
                        'status' => 'waiting',
                    ]);
                } else {
                    $assignment->update(['sequence_number' => $signer['sequence_number']]);
                }
            }

            $this->audit->record('version.signers_updated', $locked->document, $locked, $actor, [
                'signer_count' => count($signers), 'signing_mode' => $locked->signing_mode,
            ]);

            return $locked->load('assignments.placeholders');
        });
    }

    /** @param list<array{page_number: int, x: float, y: float, width: float, height: float}> $placeholders */
    public function updatePlaceholders(User $actor, SignerAssignment $assignment, array $placeholders): SignerAssignment
    {
        return DB::transaction(function () use ($actor, $assignment, $placeholders): SignerAssignment {
            $version = $this->workflow->lock($assignment->version);
            $this->workflow->assertDraft($version);
            $this->workflow->assertCurrent($version);
            $locked = $version->assignments()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();

            if ($placeholders !== []) {
                $this->preview->verify($version);
            }

            foreach ($placeholders as $index => $placeholder) {
                if ($placeholder['page_number'] > $version->preview_page_count) {
                    throw ValidationException::withMessages(["placeholders.$index.page_number" => 'Halaman tidak tersedia pada preview.']);
                }
            }

            $locked->placeholders()->delete();
            foreach ($placeholders as $placeholder) {
                $locked->placeholders()->create($placeholder + ['needs_review' => false]);
            }

            $this->audit->record('assignment.placeholders_updated', $version->document, $version, $actor, [
                'assignment_guid' => $locked->guid,
                'placeholder_count' => count($placeholders),
                'content_hash' => $version->content_hash,
            ]);

            return $locked->setRelation('version', $version)->load('placeholders');
        });
    }
}
