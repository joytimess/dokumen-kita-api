<?php

namespace Tests\Feature;

use App\Contracts\SignatureImageClassifierInterface;
use App\Enums\SignatureImageClassification;
use App\Models\SignatureAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignatureAssetApiTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    /** @return array<string, array{string, string}> */
    public static function formats(): array
    {
        return ['png upload' => ['png', 'upload'], 'jpeg upload' => ['jpg', 'upload'], 'webp upload' => ['webp', 'upload'], 'canvas drawn' => ['png', 'drawn']];
    }

    #[DataProvider('formats')]
    public function test_readable_upload_without_realpath_can_be_stored_and_used_for_signing(string $format, string $source): void
    {
        $signer = User::factory()->create();
        $version = $this->publishedVersion(User::factory()->create(), [$signer]);
        $assignment = $version->assignments()->firstOrFail();
        Storage::fake('public');
        $this->mock(SignatureImageClassifierInterface::class)->shouldReceive('classify')->once()->andReturn(SignatureImageClassification::Signature);
        $file = UploadedFileFactory::withoutRealPath($this->signatureImage('transparent-ink', $format));
        $this->assertTrue($file->isValid());
        $this->assertTrue($file->isReadable());
        $this->assertFalse($file->getRealPath());

        $created = $this->actingAs($signer)->post('/api/v1/signature-assets', [
            'file' => $file, 'source_type' => $source,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonPath('data.source_type', $source)->assertJsonMissingPath('data.file_path');
        $asset = SignatureAsset::where('guid', $created->json('data.guid'))->firstOrFail();
        $bytes = Storage::disk('local')->get($asset->file_path);
        $this->assertSame(hash('sha256', $bytes), $asset->file_hash);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($bytes)[2]);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->get('/api/v1/signature-assets/'.$asset->guid.'/image')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->postJson('/api/v1/assignments/'.$assignment->guid.'/read-confirmation', [
            'confirmed' => true, 'version_content_hash' => $version->content_hash,
        ])->assertCreated();
        $this->postJson('/api/v1/assignments/'.$assignment->guid.'/signatures', [
            'asset_guid' => $asset->guid, 'confirmed' => true, 'version_content_hash' => $version->content_hash,
            'idempotency_key' => 'upload-path-regression',
        ])->assertCreated()->assertJsonPath('data.asset_guid', $asset->guid);
        $this->assertSame('signed', $assignment->fresh()->status);
        $this->assertDatabaseHas('signatures', ['asset_guid' => $asset->guid, 'assignment_guid' => $assignment->guid]);
    }

    #[DataProvider('formats')]
    public function test_image_is_normalized_to_private_png_without_storage_path(string $format, string $source): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->mock(SignatureImageClassifierInterface::class)->shouldReceive('classify')->once()->andReturn(SignatureImageClassification::Signature);
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/signature-assets', ['file' => $this->signatureImage('ink', $format), 'source_type' => $source])
            ->assertCreated()->assertJsonPath('data.mime_type', 'image/png')->assertJsonPath('data.width', 120)
            ->assertJsonMissingPath('data.file_path')->assertJsonMissingPath('data.user_guid');

        $asset = SignatureAsset::where('guid', $response->json('data.guid'))->firstOrFail();
        $bytes = Storage::disk('local')->get($asset->file_path);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8));
        $this->assertSame(hash('sha256', $bytes), $asset->file_hash);
        $this->assertStringStartsWith('signature-assets/'.$owner->guid.'/', $asset->file_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /** @return array<string, array{string}> */
    public static function blankImages(): array
    {
        return ['white' => ['white'], 'transparent' => ['transparent'], 'solid opaque' => ['solid']];
    }

    #[DataProvider('blankImages')]
    public function test_blank_image_returns_422_and_is_not_stored(string $kind): void
    {
        Storage::fake('local');

        $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', ['file' => $this->signatureImage($kind), 'source_type' => 'upload'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'SIGNATURE_IMAGE_EMPTY');

        $this->assertDatabaseCount('signature_assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_transparent_background_with_ink_is_accepted(): void
    {
        Storage::fake('local');
        $this->mock(SignatureImageClassifierInterface::class)->shouldReceive('classify')->once()->andReturn(SignatureImageClassification::Signature);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', ['file' => $this->signatureImage('transparent-ink'), 'source_type' => 'drawn'])
            ->assertCreated();
        $this->assertDatabaseCount('signature_assets', 1);
    }

    public function test_mismatched_extension_and_oversized_dimensions_return_422(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => UploadedFileFactory::content('signature.png', 'not an image'), 'source_type' => 'upload',
        ])->assertUnprocessable();
        config(['documents.signature_assets.max_width' => 100]);
        $this->postJson('/api/v1/signature-assets', ['file' => $this->signatureImage(), 'source_type' => 'drawn'])->assertUnprocessable();
        $this->assertDatabaseCount('signature_assets', 0);
    }

    public function test_asset_library_is_owner_only_and_images_require_authorization(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownAsset = $this->asset($owner);
        $otherAsset = $this->asset($other);

        $this->actingAs($owner)->getJson('/api/v1/signature-assets')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.guid', $ownAsset->guid)->assertJsonMissingPath('data.0.file_path');
        $this->get("/api/v1/signature-assets/{$ownAsset->guid}/image")->assertOk()->assertHeader('Content-Type', 'image/png')->assertStreamed();
        $this->getJson("/api/v1/signature-assets/{$otherAsset->guid}/image")->assertNotFound();
    }

    public function test_asset_library_respects_requested_page_size_and_keeps_default(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        for ($index = 0; $index < 7; $index++) {
            $this->asset($owner);
        }

        $this->actingAs($owner)->getJson('/api/v1/signature-assets?per_page=6')
            ->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('meta.per_page', 6)
            ->assertJsonPath('meta.total', 7)->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/signature-assets?page=2&per_page=6')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 6);

        $this->getJson('/api/v1/signature-assets')
            ->assertOk()->assertJsonCount(7, 'data')->assertJsonPath('meta.per_page', 15);
    }

    public function test_guests_cannot_upload_list_or_view_assets(): void
    {
        Storage::fake('local');
        $asset = $this->asset(User::factory()->create());

        $this->postJson('/api/v1/signature-assets', [])->assertUnauthorized();
        $this->getJson('/api/v1/signature-assets')->assertUnauthorized();
        $this->getJson("/api/v1/signature-assets/{$asset->guid}/image")->assertUnauthorized();
    }

    public function test_only_owner_can_delete_an_unused_asset_without_reclassification(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $asset = $this->asset($owner);
        $this->mock(SignatureImageClassifierInterface::class)->shouldNotReceive('classify');

        $this->actingAs(User::factory()->create())->deleteJson('/api/v1/signature-assets/'.$asset->guid)->assertNotFound();
        Storage::disk('local')->assertExists($asset->file_path);
        $this->actingAs($owner)->deleteJson('/api/v1/signature-assets/'.$asset->guid)->assertNoContent();
        Storage::disk('local')->assertMissing($asset->file_path);
        $this->assertDatabaseMissing('signature_assets', ['guid' => $asset->guid]);
    }
}
