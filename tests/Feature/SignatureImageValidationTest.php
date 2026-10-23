<?php

namespace Tests\Feature;

use App\Contracts\SignatureImageClassifierInterface;
use App\Enums\SignatureImageClassification;
use App\Exceptions\ApiException;
use App\Models\SignatureAsset;
use App\Models\User;
use App\Services\SignatureAssetService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class SignatureImageValidationTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public static function rejectedClassifications(): array
    {
        // These labels exercise API decisions with a fake, not actual image recognition accuracy.
        return [
            'cartoon penguin' => [SignatureImageClassification::NonSignature, 'upload'],
            'animal photo' => [SignatureImageClassification::NonSignature, 'upload'],
            'meme' => [SignatureImageClassification::NonSignature, 'upload'],
            'company logo' => [SignatureImageClassification::NonSignature, 'upload'],
            'screenshot' => [SignatureImageClassification::NonSignature, 'upload'],
            'spoofed drawn' => [SignatureImageClassification::NonSignature, 'drawn'],
            'uncertain' => [SignatureImageClassification::Uncertain, 'upload'],
        ];
    }

    #[DataProvider('rejectedClassifications')]
    public function test_unverified_classifications_never_persist(SignatureImageClassification $result, string $source): void
    {
        $this->mock(SignatureImageClassifierInterface::class)->shouldReceive('classify')->once()->andReturn($result);
        $response = $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => $this->signatureImage(), 'source_type' => $source,
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['file']]]);
        $response->assertJsonPath('error.code', $result === SignatureImageClassification::NonSignature ? 'SIGNATURE_IMAGE_NOT_SIGNATURE' : 'SIGNATURE_IMAGE_UNCERTAIN');
        if ($result === SignatureImageClassification::NonSignature) {
            $response->assertJsonPath('error.message', 'Gambar yang diunggah bukan tanda tangan.');
        }
        $this->assertNothingStored();
    }

    public static function outages(): array
    {
        return [
            'timeout' => [new ConnectionException('secret timeout payload')],
            'unavailable' => [new RuntimeException('secret provider response')],
        ];
    }

    #[DataProvider('outages')]
    public function test_provider_errors_return_503_without_exposing_details(Throwable $exception): void
    {
        $this->mock(SignatureImageClassifierInterface::class)->shouldReceive('classify')->once()->andThrow($exception);
        $response = $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => $this->signatureImage(), 'source_type' => 'upload',
        ])->assertStatus(503)->assertJsonPath('error.code', 'SIGNATURE_CLASSIFIER_UNAVAILABLE');
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertNothingStored();
    }

    public static function unconfigured(): array
    {
        return ['default upload' => ['unconfigured', 'upload'], 'default drawn' => ['unconfigured', 'drawn'], 'unknown driver' => ['invalid-driver', 'upload']];
    }

    #[DataProvider('unconfigured')]
    public function test_missing_or_unknown_provider_configuration_is_closed(string $driver, string $source): void
    {
        config(['documents.signature_assets.classifier' => $driver]);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => $this->signatureImage(), 'source_type' => $source,
        ])->assertStatus(503)->assertJsonPath('error.code', 'SIGNATURE_CLASSIFIER_UNAVAILABLE');
        $this->assertNothingStored();
    }

    public static function invalidFiles(): array
    {
        return [
            'text with png extension' => ['signature.png', 'not an image'],
            'svg' => ['signature.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60"><path d="M1 1L100 50"/></svg>'],
            'svg disguised as png' => ['signature.png', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
            'gif disguised as png' => ['signature.png', base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==')],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_content_is_rejected_before_classification(string $name, string $bytes): void
    {
        $this->mock(SignatureImageClassifierInterface::class)->shouldNotReceive('classify');
        $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => UploadedFileFactory::content($name, $bytes), 'source_type' => 'upload',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['file']]]);
        $this->assertNothingStored();
    }

    public function test_corrupt_png_with_valid_header_is_not_decoded_or_saved(): void
    {
        $this->mock(SignatureImageClassifierInterface::class)->shouldNotReceive('classify');
        $file = $this->signatureImage();
        $bytes = file_get_contents($file->getRealPath());
        $corrupt = substr($bytes, 0, 33).str_repeat("\0", 30);
        $this->assertIsArray(getimagesizefromstring($corrupt));
        $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => UploadedFileFactory::content('signature.png', $corrupt), 'source_type' => 'upload',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'SIGNATURE_IMAGE_INVALID');
        $this->assertNothingStored();
    }

    public function test_pixel_budget_is_enforced_before_classification(): void
    {
        config(['documents.signature_assets.max_pixels' => 7_000]);
        $this->mock(SignatureImageClassifierInterface::class)->shouldNotReceive('classify');
        $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => $this->signatureImage(), 'source_type' => 'upload',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'SIGNATURE_IMAGE_INVALID');
        $this->assertNothingStored();
    }

    public function test_file_size_limit_is_enforced_even_for_direct_service_calls(): void
    {
        $this->mock(SignatureImageClassifierInterface::class)->shouldNotReceive('classify');
        $file = $this->signatureImage();
        $file = UploadedFileFactory::content('signature.png', file_get_contents($file->getRealPath()).str_repeat('x', 2_097_152));
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/signature-assets', ['file' => $file, 'source_type' => 'upload'])->assertUnprocessable();
        try {
            $this->app->make(SignatureAssetService::class)->store($user, $file, 'upload');
            $this->fail('Direct callers must not bypass the byte limit.');
        } catch (ApiException $e) {
            $this->assertSame('SIGNATURE_IMAGE_INVALID', $e->errorCode);
        }
        $this->assertNothingStored();
    }

    public function test_normalized_bytes_are_classified_and_metadata_is_removed(): void
    {
        $file = $this->signatureImage();
        $bytes = file_get_contents($file->getRealPath());
        $text = "Comment\0private-metadata";
        $chunk = pack('N', strlen($text)).'tEXt'.$text.pack('N', crc32('tEXt'.$text));
        $bytes = substr($bytes, 0, 33).$chunk.substr($bytes, 33).'<script>untrusted trailer</script>';
        $classified = null;
        $this->mock(SignatureImageClassifierInterface::class)->shouldReceive('classify')->once()->withArgs(function (string $png) use (&$classified): bool {
            $classified = $png;

            return ! str_contains($png, 'private-metadata') && ! str_contains($png, '<script>') && getimagesizefromstring($png)[2] === IMAGETYPE_PNG;
        })->andReturn(SignatureImageClassification::Signature);
        $response = $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => UploadedFileFactory::content('signature.png', $bytes), 'source_type' => 'upload',
        ])->assertCreated();
        $asset = SignatureAsset::where('guid', $response->json('data.guid'))->firstOrFail();
        $this->assertSame($classified, Storage::disk('local')->get($asset->file_path));
    }

    public function test_storage_write_explicitly_requests_private_visibility(): void
    {
        $this->mock(SignatureImageClassifierInterface::class)->shouldReceive('classify')->once()->andReturn(SignatureImageClassification::Signature);
        $disk = Mockery::mock(FilesystemAdapter::class);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);
        $disk->shouldReceive('put')->once()->withArgs(fn (string $path, string $png, array $options): bool => preg_match('~^signature-assets/[^/]+/[A-Za-z0-9]{40}\.png$~', $path) === 1
            && $options === ['visibility' => 'private'] && getimagesizefromstring($png)[2] === IMAGETYPE_PNG
        )->andReturn(true);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => $this->signatureImage(), 'source_type' => 'upload',
        ])->assertCreated()->assertJsonMissingPath('data.file_path');
    }

    public function test_expensive_requests_are_rate_limited_per_user(): void
    {
        config(['documents.signature_assets.classification_per_minute' => 1]);
        $this->mock(SignatureImageClassifierInterface::class)->shouldReceive('classify')->once()->andReturn(SignatureImageClassification::Signature);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => $this->signatureImage(), 'source_type' => 'drawn',
        ])->assertCreated();
        $this->postJson('/api/v1/signature-assets', ['file' => $this->signatureImage(), 'source_type' => 'upload'])->assertTooManyRequests();
        $this->assertDatabaseCount('signature_assets', 1);
    }

    public function test_unauthenticated_upload_does_not_call_classifier(): void
    {
        $this->mock(SignatureImageClassifierInterface::class)->shouldNotReceive('classify');
        $this->postJson('/api/v1/signature-assets', ['file' => $this->signatureImage(), 'source_type' => 'upload'])->assertUnauthorized();
        $this->assertNothingStored();
    }

    private function assertNothingStored(): void
    {
        $this->assertDatabaseCount('signature_assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
