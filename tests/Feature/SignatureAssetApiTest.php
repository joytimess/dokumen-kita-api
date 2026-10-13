<?php

namespace Tests\Feature;

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
    public function test_image_is_normalized_to_private_png_without_storage_path(string $format, string $source): void
    {
        Storage::fake('local');
        Storage::fake('public');
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

    public function test_guests_cannot_upload_list_or_view_assets(): void
    {
        Storage::fake('local');
        $asset = $this->asset(User::factory()->create());

        $this->postJson('/api/v1/signature-assets', [])->assertUnauthorized();
        $this->getJson('/api/v1/signature-assets')->assertUnauthorized();
        $this->getJson("/api/v1/signature-assets/{$asset->guid}/image")->assertUnauthorized();
    }
}
