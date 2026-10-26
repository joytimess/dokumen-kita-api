<?php

namespace Tests\Feature;

use App\Enums\VersionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentViewerApiTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    public function test_owner_and_ready_signer_can_stream_verified_snapshot(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->publishedVersion($owner, [$signer]);

        $response = $this->actingAs($signer)->get("/api/v1/versions/{$version->guid}/pdf")
            ->assertOk()->assertStreamed()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Document-Content-Hash', $version->content_hash);
        $this->assertSame($version->content_hash, hash('sha256', $response->streamedContent()));
        $this->actingAs($owner)->get("/api/v1/versions/{$version->guid}/pdf")->assertOk();
    }

    public function test_waiting_signer_gets_403_and_outsider_gets_404(): void
    {
        $owner = User::factory()->create();
        $waiting = User::factory()->create();
        $version = $this->publishedVersion($owner, [User::factory()->create(), $waiting]);

        $this->actingAs($waiting)->getJson("/api/v1/versions/{$version->guid}/pdf")->assertForbidden();
        $this->getJson("/api/v1/documents/{$version->document_guid}")->assertJsonMissingPath('data.current_version.content_hash');
        $this->actingAs(User::factory()->create())->getJson("/api/v1/versions/{$version->guid}/pdf")->assertNotFound();
    }

    public function test_signed_signer_retains_historical_snapshot_access(): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $version->assignments()->update(['status' => 'signed']);
        $version->update(['status' => VersionStatus::Superseded]);

        $this->actingAs($signer)->get("/api/v1/versions/{$version->guid}/pdf")->assertOk();
    }

    public function test_missing_snapshot_returns_409(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        Storage::disk('local')->delete($version->preview_pdf_path);

        $this->actingAs($owner)->getJson("/api/v1/versions/{$version->guid}/pdf")
            ->assertConflict()->assertJsonPath('error.code', 'PREVIEW_NOT_READY');
    }

    public function test_completed_version_serves_final_pdf_and_checks_its_hash(): void
    {
        $owner = User::factory()->create();
        $version = $this->publishedVersion($owner, [$owner]);
        $path = "documents/{$version->document_guid}/final.pdf";
        $bytes = Storage::disk('local')->get($version->preview_pdf_path)."\n% final fixture\n";
        Storage::disk('local')->put($path, $bytes);
        $version->assignments()->firstOrFail()->signature()->create([
            'asset_guid' => $this->asset($owner)->guid, 'version_content_hash' => $version->content_hash, 'signed_at' => now(),
        ]);
        $version->update(['status' => VersionStatus::Completed, 'final_pdf_path' => $path, 'final_pdf_hash' => hash('sha256', $bytes)]);

        $this->actingAs($owner)->get("/api/v1/versions/{$version->guid}/pdf")->assertOk()->assertStreamedContent($bytes);
        Storage::disk('local')->put($path, '%PDF-tampered');
        $this->getJson("/api/v1/versions/{$version->guid}/pdf")->assertConflict()->assertJsonPath('error.code', 'PDF_MISMATCH');
    }

    public function test_guests_cannot_view_pdf_or_confirm_or_sign(): void
    {
        $owner = User::factory()->create();
        $version = $this->publishedVersion($owner, [$owner]);
        $assignment = $version->assignments()->firstOrFail();

        $this->getJson("/api/v1/versions/{$version->guid}/pdf")->assertUnauthorized();
        $this->postJson("/api/v1/assignments/{$assignment->guid}/read-confirmation", [])->assertUnauthorized();
        $this->postJson("/api/v1/assignments/{$assignment->guid}/signatures", [])->assertUnauthorized();
    }
}
