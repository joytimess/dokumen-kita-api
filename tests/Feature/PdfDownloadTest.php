<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Enums\VersionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Tests\TestCase;

class PdfDownloadTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    public function test_final_download_uses_last_signature_date_in_jakarta_and_preserves_binary(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->publishedVersion($owner, [$owner, $signer]);
        $version->document->update(['title' => 'Surat Pernyataan Pengunduran Diri']);
        $asset = $this->asset($owner);
        foreach ($version->assignments as $index => $assignment) {
            $assignment->signature()->create([
                'asset_guid' => $asset->guid,
                'version_content_hash' => $version->content_hash,
                'signed_at' => $index === 0 ? '2026-10-25 16:59:00' : '2026-10-25 17:01:00',
            ]);
            $assignment->update(['status' => 'signed']);
        }
        $bytes = Storage::disk('local')->get($version->preview_pdf_path)."\n% final snapshot\n";
        $path = "documents/{$version->document_guid}/final/{$version->guid}.pdf";
        Storage::disk('local')->put($path, $bytes);
        $version->update(['status' => VersionStatus::Completed, 'final_pdf_path' => $path, 'final_pdf_hash' => hash('sha256', $bytes)]);
        $original = $version->fresh()->getAttributes();

        $this->travelTo(now()->setDate(2026, 11, 10));
        $expected = 'attachment; filename=Surat_Pernyataan_Pengunduran_Diri_Signed_2026-10-26.pdf';
        foreach ([$owner, $signer, $owner] as $user) {
            $response = $this->actingAs($user)->get("/api/v1/versions/{$version->guid}/pdf?download=1")
                ->assertOk()->assertStreamed()->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Content-Disposition', $expected)
                ->assertHeader('X-Document-Content-Hash', $version->content_hash);
            $actual = $response->streamedContent();
            $this->assertSame($bytes, $actual);
            $this->assertStringStartsWith('%PDF-', $actual);
            $this->assertSame($version->final_pdf_hash, hash('sha256', $actual));
            $this->assertCount(2, (new Parser)->parseContent($actual)->getPages());
        }
        $this->travelBack();
        $this->assertSame($original, $version->fresh()->getAttributes());
        $this->assertSame($bytes, Storage::disk('local')->get($path));
        $this->get("/api/v1/versions/{$version->guid}/pdf")
            ->assertOk()->assertHeader('Content-Disposition', str_replace('attachment;', 'inline;', $expected))
            ->assertStreamedContent($bytes);
    }

    public static function titles(): array
    {
        return [
            'normal' => ['Surat Pernyataan', 'Surat_Pernyataan'],
            'long ascii' => [str_repeat('Panjang ', 100), null],
            'long unicode' => [str_repeat('契約書 ', 100), null],
            'unicode and unsafe characters' => ["  Résumé / 契約書 \\ :*?\"<>| \r\n\0\u{202E}  ", 'Résumé_契約書'],
            'empty' => ['', 'Dokumen'],
            'unsafe only' => ["../\\:*?\"<>|\r\n\0", 'Dokumen'],
            'percent and emoji' => ['Persetujuan 100% ✅', 'Persetujuan_100%_✅'],
            'unicode transliterated to path separator' => ['Rasio ½ ／ 価格', 'Rasio_½_／_価格'],
            'reserved device title' => ['CON', 'CON'],
        ];
    }

    #[DataProvider('titles')]
    public function test_unsigned_download_sanitizes_and_bounds_filename(string $title, ?string $expectedTitle): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $version->document->update(['title' => $title]);
        $version->forceFill(['created_at' => '2026-10-20 17:01:00'])->save();
        $version->document->forceFill(['created_at' => '2026-10-01 00:00:00'])->save();
        $bytes = Storage::disk('local')->get($version->preview_pdf_path);

        $response = $this->actingAs($owner)->get("/api/v1/versions/{$version->guid}/pdf?download=1")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertStreamedContent($bytes);
        $header = $response->headers->get('Content-Disposition');
        $params = HeaderUtils::combine(HeaderUtils::split($header, ';='));
        $filename = isset($params['filename*']) ? rawurldecode(substr($params['filename*'], 7)) : $params['filename'];

        $this->assertStringStartsWith('attachment;', $header);
        $this->assertStringEndsWith('_2026-10-21.pdf', $filename);
        $this->assertStringNotContainsString('_Signed_', $filename);
        $this->assertLessThanOrEqual(180, strlen($filename));
        $this->assertLessThanOrEqual(180, strlen($params['filename']));
        $this->assertMatchesRegularExpression('/^[\x20-\x7e]+$/', $params['filename']);
        $this->assertDoesNotMatchRegularExpression('/[<>:"\/\\\\|?*%\s]/', $params['filename']);
        $this->assertDoesNotMatchRegularExpression('/[\p{C}<>:"\/\\\\|?*\s]/u', $filename);
        $this->assertTrue(mb_check_encoding($filename, 'UTF-8'));
        if ($expectedTitle !== null) {
            $this->assertSame($expectedTitle.'_2026-10-21.pdf', $filename);
        }
        $this->get("/api/v1/versions/{$version->guid}/pdf?download=1")->assertHeader('Content-Disposition', $header);
        $this->get("/api/v1/versions/{$version->guid}/pdf")
            ->assertOk()->assertHeader('Content-Disposition', str_replace('attachment;', 'inline;', $header))
            ->assertStreamedContent($bytes);
    }

    public function test_download_preserves_existing_authorization_and_hash_checks(): void
    {
        $owner = User::factory()->create();
        $ready = User::factory()->create();
        $waiting = User::factory()->create();
        $version = $this->publishedVersion($owner, [$ready, $waiting]);
        $url = "/api/v1/versions/{$version->guid}/pdf?download=1";

        foreach ([$owner, $ready] as $user) {
            $this->actingAs($user)->get($url)->assertOk();
        }
        $this->actingAs($waiting)->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        Storage::disk('local')->put($version->preview_pdf_path, "\r\n%PDF-tampered");
        $this->actingAs($owner)->getJson($url)->assertConflict()->assertJsonPath('error.code', 'PREVIEW_MISMATCH');
    }

    public function test_signed_assignment_does_not_label_unfinished_version_as_signed(): void
    {
        $owner = User::factory()->create();
        $version = $this->publishedVersion($owner, [$owner, User::factory()->create()]);
        $version->assignments()->where('sequence_number', 1)->update(['status' => 'signed']);
        foreach ([VersionStatus::InProgress, VersionStatus::Finalizing, VersionStatus::Superseded, VersionStatus::Rejected] as $status) {
            $version->update(['status' => $status]);
            $response = $this->actingAs($owner)->get("/api/v1/versions/{$version->guid}/pdf?download=1")->assertOk();
            $this->assertStringNotContainsString('_Signed_', $response->headers->get('Content-Disposition'));
        }
    }

    public function test_cross_origin_client_can_read_filename_without_exposing_storage(): void
    {
        config(['cors.allowed_origins' => ['https://frontend.example.test']]);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $response = $this->actingAs($owner)->withHeader('Origin', 'https://frontend.example.test')
            ->get("/api/v1/versions/{$version->guid}/pdf?download=1")->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example.test')
            ->assertHeaderMissing('Location');
        $this->assertContains('content-disposition', array_map('trim', explode(',', strtolower($response->headers->get('Access-Control-Expose-Headers')))));
        $this->assertStringNotContainsString($version->preview_pdf_path, (string) $response->headers);
    }

    public function test_source_pdf_stays_inline_with_unsigned_title_and_owner_only_access(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->publishedVersion($owner, [$signer]);
        $version->document->update(['title' => 'Sumber Dokumen']);
        $version->forceFill(['created_at' => '2026-10-20 17:01:00'])->save();
        $version->update(['source_type' => SourceType::Pdf, 'source_path' => $version->preview_pdf_path]);
        $url = "/api/v1/versions/{$version->guid}/source";
        $this->actingAs($owner)->get($url)->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename=Sumber_Dokumen_2026-10-21.pdf')
            ->assertStreamedContent(Storage::disk('local')->get($version->source_path));
        $this->actingAs($signer)->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();

        $version->update(['source_type' => SourceType::Docx, 'source_path' => 'documents/source.docx']);
        Storage::disk('local')->put($version->source_path, 'PK docx fixture');
        $this->actingAs($owner)->get($url)->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$version->guid.'.docx"')
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->assertStreamedContent('PK docx fixture');
    }

    public function test_guests_cannot_download_and_invalid_download_flag_is_rejected(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $this->getJson("/api/v1/versions/{$version->guid}/pdf?download=1")->assertUnauthorized();
        $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}/pdf?download=invalid")->assertUnprocessable();
    }

    public function test_completed_pdf_without_signature_metadata_does_not_invent_completion_date(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $version->update(['status' => VersionStatus::Completed, 'final_pdf_path' => $version->preview_pdf_path, 'final_pdf_hash' => $version->content_hash]);
        $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}/pdf?download=1")
            ->assertConflict()->assertJsonPath('error.code', 'SIGNING_COMPLETION_NOT_FOUND');
    }
}
