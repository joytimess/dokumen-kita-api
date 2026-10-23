<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\SignatureAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class GroqSignatureImageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Group('groq-integration')]
    public function test_real_provider_against_labeled_images_and_write_safe_metrics(): void
    {
        if (getenv('RUN_GROQ_SIGNATURE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Opt in with RUN_GROQ_SIGNATURE_INTEGRATION=1; sends labeled fixtures to Groq and uses quota.');
        }
        $this->assertNotSame('', trim((string) config('services.groq.api_key')), 'Configure GROQ_API_KEY in .env before opting in.');
        config(['documents.signature_assets.classifier' => 'groq']);
        Storage::fake('local');
        $directory = getenv('GROQ_SIGNATURE_FIXTURES') ?: base_path('tests/Fixtures/signature-images');
        $samples = json_decode(file_get_contents($directory.'/manifest.json'), true, 16, JSON_THROW_ON_ERROR);
        $this->assertIsArray($samples);
        $this->assertNotEmpty($samples);
        foreach ($samples as $sample) {
            $this->assertMatchesRegularExpression('/\A[a-zA-Z0-9_-]+\z/', $sample['id']);
            $this->assertSame(basename($sample['file']), $sample['file']);
            $this->assertContains($sample['expected'], ['signature', 'non_signature']);
            $this->assertFileExists($directory.'/'.$sample['file']);
        }

        $metadata = [];
        Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$metadata): void {
            if ($event->request->url() !== 'https://api.groq.com/openai/v1/chat/completions') {
                return;
            }
            $metadata['http_status'] = $event->response->status();
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $field) {
                $value = $event->response->json('usage.'.$field);
                if (is_int($value)) {
                    $metadata['usage'][$field] = $value;
                }
            }
            foreach (['limit-requests', 'remaining-requests', 'limit-tokens', 'remaining-tokens'] as $field) {
                $value = $event->response->header('x-ratelimit-'.$field);
                if (is_string($value) && ctype_digit($value)) {
                    $metadata['quota'][$field] = (int) $value;
                }
            }
        });

        $actor = User::factory()->create();
        $service = app(SignatureAssetService::class);
        $results = [];
        $summary = ['samples' => count($samples), 'correct' => 0, 'false_acceptance' => 0, 'false_rejection' => 0, 'uncertain' => 0, 'unavailable_or_invalid' => 0];
        $configuredDelay = getenv('GROQ_EVALUATION_DELAY_MS');
        $delayMs = min(60_000, max(0, $configuredDelay === false ? 35_000 : (int) $configuredDelay));
        foreach ($samples as $sample) {
            if ($results !== []) {
                usleep($delayMs * 1000);
            }
            $metadata = [];
            $started = hrtime(true);
            $actual = 'unavailable_or_invalid';
            try {
                $file = UploadedFileFactory::content($sample['file'], file_get_contents($directory.'/'.$sample['file']));
                $asset = $service->store($actor, $file, 'upload');
                $actual = 'signature';
                $service->delete($asset);
            } catch (ApiException $exception) {
                $actual = match ($exception->errorCode) {
                    'SIGNATURE_IMAGE_NOT_SIGNATURE' => 'non_signature',
                    'SIGNATURE_IMAGE_UNCERTAIN' => 'uncertain',
                    default => 'unavailable_or_invalid',
                };
            }
            $results[] = ['id' => $sample['id'], 'expected' => $sample['expected'], 'actual' => $actual,
                'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 2), ...$metadata];
            if ($actual === $sample['expected']) {
                $summary['correct']++;
            } elseif ($actual === 'uncertain' || $actual === 'unavailable_or_invalid') {
                $summary[$actual]++;
            } elseif ($actual === 'signature') {
                $summary['false_acceptance']++;
            } else {
                $summary['false_rejection']++;
            }
        }
        $report = ['evaluated_at' => now()->toIso8601String(), 'model' => config('services.groq.signature_model'),
            'manifest_sha256' => hash_file('sha256', $directory.'/manifest.json'), 'summary' => $summary, 'results' => $results];
        $reportPath = storage_path('app/private/groq-signature-evaluation.json');
        $this->assertNotFalse(file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->assertSame($summary['samples'], $summary['correct'], 'See storage/app/private/groq-signature-evaluation.json for actual outcomes; a small smoke dataset does not establish production accuracy.');
    }
}
