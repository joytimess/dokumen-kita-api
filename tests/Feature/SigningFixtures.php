<?php

namespace Tests\Feature;

use App\Enums\PreviewStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\EmailNotification;
use App\Models\SignaturePlaceholder;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

trait SigningFixtures
{
    /** @param array<string, mixed> $attributes */
    private function draft(User $owner, array $attributes = []): DocumentVersion
    {
        $document = Document::factory()->create(['owner_guid' => $owner->guid]);

        return DocumentVersion::factory()->create(['document_guid' => $document->guid] + $attributes);
    }

    /** @param list<string> $emails */
    private function preparedDraft(User $owner, array $emails = ['first@example.test', 'second@example.test']): DocumentVersion
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $version = $this->draft($owner);
        $this->storePreview($version);

        foreach ($emails as $index => $email) {
            $assignment = SignerAssignment::factory()->create([
                'version_guid' => $version->guid,
                'recipient_email' => $email,
                'normalized_recipient_email' => $email,
                'sequence_number' => $index + 1,
            ]);
            SignaturePlaceholder::factory()->create(['assignment_guid' => $assignment->guid]);
        }

        return $version;
    }

    private function storePreview(DocumentVersion $version): void
    {
        $version->refresh();
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 5\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
        $path = "documents/{$version->document_guid}/previews/{$version->guid}.pdf";
        Storage::disk('local')->put($path, $pdf);
        $version->update([
            'preview_status' => PreviewStatus::Ready,
            'preview_pdf_path' => $path,
            'preview_page_count' => 2,
            'content_hash' => hash('sha256', $pdf),
        ]);
    }

    private function invitationToken(SignerAssignment $assignment): string
    {
        $notification = EmailNotification::where('assignment_guid', $assignment->guid)->where('status', 'queued')->latest('id')->firstOrFail();
        parse_str(parse_url($notification->payload['invitation_url'], PHP_URL_QUERY), $query);

        return $query['token'];
    }

    /** @return array{page_number: int, x: float, y: float, width: float, height: float} */
    private function position(): array
    {
        return ['page_number' => 2, 'x' => 0.7, 'y' => 0.8, 'width' => 0.3, 'height' => 0.2];
    }
}
