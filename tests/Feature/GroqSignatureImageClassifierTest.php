<?php

namespace Tests\Feature;

use App\Contracts\SignatureImageClassifierInterface;
use App\Models\SignatureAsset;
use App\Models\User;
use App\Services\GroqSignatureImageClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroqSignatureImageClassifierTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'documents.signature_assets.classifier' => 'groq',
            'services.groq.api_key' => 'test-secret-key',
            'services.groq.signature_model' => 'qwen/qwen3.8-27b',
            'services.groq.connect_timeout' => 3,
            'services.groq.timeout' => 15,
        ]);
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private static function completion(string $content, string $finish = 'stop'): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finish]]];
    }

    public function test_request_sends_only_normalized_png_and_strict_instructions(): void
    {
        $this->assertInstanceOf(GroqSignatureImageClassifier::class, app(SignatureImageClassifierInterface::class));
        $options = null;
        Http::fake([self::ENDPOINT => function (Request $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response(self::completion('{"classification":"signature"}'));
        }]);
        $file = $this->signatureImage();
        $uploaded = file_get_contents($file->getRealPath()).'private-trailer';
        $response = $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => UploadedFileFactory::content('signature.png', $uploaded), 'source_type' => 'drawn',
        ])->assertCreated();
        $asset = SignatureAsset::where('guid', $response->json('data.guid'))->firstOrFail();
        $stored = Storage::disk('local')->get($asset->file_path);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT
            && $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer test-secret-key')
            && $request['model'] === 'qwen/qwen3.8-27b' && $request['response_format'] === ['type' => 'json_object']
            && $request['temperature'] === 0 && $request['max_completion_tokens'] === 128
            && $request['reasoning_effort'] === 'none' && $request['stream'] === false
            && $request['messages'][0]['role'] === 'system'
            && str_contains($request['messages'][0]['content'], 'untrusted visual content')
            && $request['messages'][1]['content'][1]['image_url']['url'] === 'data:image/png;base64,'.base64_encode($stored));
        $this->assertStringNotContainsString('private-trailer', $stored);
        $this->assertSame(3, $options['connect_timeout']);
        $this->assertSame(15, $options['timeout']);
        $this->assertFalse($options['allow_redirects']);
        Http::assertSentCount(1);
    }

    public static function candidates(): array
    {
        return [
            'handwritten cursive' => ['cursive.jpg', 'signature', 'upload'],
            'short initials' => ['initials.png', 'signature', 'upload'],
            'transparent PNG' => ['transparent.png', 'signature', 'drawn'],
            'scanned paper' => ['paper.png', 'signature', 'upload'],
            'cartoon penguin' => ['penguin.png', 'non_signature', 'upload'],
            'animal photograph' => ['animal-photo.jpg', 'non_signature', 'upload'],
            'meme' => ['meme.png', 'non_signature', 'upload'],
            'logo' => ['logo.png', 'non_signature', 'upload'],
            'screenshot' => ['screenshot.png', 'non_signature', 'upload'],
            'source_type spoof' => ['penguin.png', 'non_signature', 'drawn'],
            'uncertain' => ['initials.png', 'uncertain', 'upload'],
        ];
    }

    #[DataProvider('candidates')]
    public function test_api_uses_groq_decision_without_bypass(string $fixture, string $classification, string $source): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::completion(json_encode(['classification' => $classification])))]);
        $response = $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => UploadedFileFactory::content($fixture, file_get_contents(base_path('tests/Fixtures/signature-images/'.$fixture))),
            'source_type' => $source,
        ]);
        if ($classification === 'signature') {
            $response->assertCreated();
            $this->assertDatabaseCount('signature_assets', 1);
            $this->assertCount(1, Storage::disk('local')->allFiles());
        } else {
            $response->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['file']]])
                ->assertJsonPath('error.code', $classification === 'uncertain' ? 'SIGNATURE_IMAGE_UNCERTAIN' : 'SIGNATURE_IMAGE_NOT_SIGNATURE');
            $this->assertNothingStored();
        }
        Http::assertSentCount(1);
    }

    public static function failures(): array
    {
        return [
            '429 quota' => [429, ['error' => ['message' => 'private-provider-response']]],
            '500 outage' => [500, ['error' => ['message' => 'private-provider-response']]],
            '401 credentials' => [401, ['error' => ['message' => 'test-secret-key']]],
            'redirect' => [302, ''],
            'invalid outer JSON' => [200, 'not JSON'],
            'missing choices' => [200, []],
            'invalid JSON content' => [200, self::completion('not JSON')],
            'unknown label' => [200, self::completion('{"classification":"valid"}')],
            'non string label' => [200, self::completion('{"classification":true}')],
            'extra fields' => [200, self::completion('{"classification":"signature","confidence":1}')],
            'missing classification' => [200, self::completion('{}')],
            'truncated response' => [200, self::completion('{"classification":"signature"}', 'length')],
            'non string content' => [200, ['choices' => [['message' => ['role' => 'assistant', 'content' => []], 'finish_reason' => 'stop']]]],
            'refusal' => [200, ['choices' => [['message' => ['role' => 'assistant', 'content' => '{"classification":"signature"}', 'refusal' => 'refused'], 'finish_reason' => 'stop']]]],
        ];
    }

    #[DataProvider('failures')]
    public function test_provider_failures_return_sanitized_503_without_persistence_or_retry(int $status, array|string $body): void
    {
        Http::fake([self::ENDPOINT => Http::response($body, $status)]);
        $response = $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => $this->signatureImage(), 'source_type' => 'upload',
        ])->assertStatus(503)->assertJsonPath('error.code', 'SIGNATURE_CLASSIFIER_UNAVAILABLE');
        $this->assertStringNotContainsString('test-secret-key', $response->getContent());
        $this->assertStringNotContainsString('private-provider-response', $response->getContent());
        $this->assertNothingStored();
        Http::assertSentCount(1);
    }

    public function test_connection_timeout_cannot_bypass_verification(): void
    {
        Http::fake([self::ENDPOINT => Http::failedConnection('private-provider-response')]);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => $this->signatureImage(), 'source_type' => 'drawn',
        ])->assertStatus(503)->assertJsonPath('error.code', 'SIGNATURE_CLASSIFIER_UNAVAILABLE');
        $this->assertNothingStored();
    }

    public function test_missing_api_key_makes_no_request(): void
    {
        config(['services.groq.api_key' => '  ']);
        Http::fake();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/signature-assets', [
            'file' => $this->signatureImage(), 'source_type' => 'upload',
        ])->assertStatus(503)->assertJsonPath('error.code', 'SIGNATURE_CLASSIFIER_UNAVAILABLE');
        Http::assertNothingSent();
        $this->assertNothingStored();
    }

    public function test_unauthorized_upload_makes_no_request(): void
    {
        Http::fake();
        $this->postJson('/api/v1/signature-assets', ['file' => $this->signatureImage(), 'source_type' => 'upload'])->assertUnauthorized();
        Http::assertNothingSent();
        $this->assertNothingStored();
    }

    private function assertNothingStored(): void
    {
        $this->assertDatabaseCount('signature_assets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
