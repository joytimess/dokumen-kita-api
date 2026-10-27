<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\PersonalDocumentAnswerService;
use App\Services\PersonalDocumentAssistantService;
use App\Services\SupportChatService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatbotDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.groq.api_key' => 'private-key-marker', 'chatbot.model' => 'test-model']);
        Http::preventStrayRequests();
        Log::spy();
    }

    private function callStage(string $stage): void
    {
        match ($stage) {
            'support_selector' => app(SupportChatService::class)->reply('How do I sign a document?'),
            'intent_router' => app(PersonalDocumentAssistantService::class)->reply(new User, 'Cari dokumen private-conversation-marker'),
            'answer_synthesis' => app(PersonalDocumentAnswerService::class)->answer('private-conversation-marker', 'id', 'count_my_documents', ['count' => 1]),
        };
    }

    public static function failures(): iterable
    {
        $cases = [
            [429, ['error' => ['message' => 'private-body-marker', 'type' => 'private-provider-type']], 'rate_limit'],
            [401, 'private-body-marker', 'http_client_error'],
            [503, 'private-body-marker', 'http_server_error'],
            [302, 'private-body-marker', 'http_redirect'],
            [200, ['choices' => []], 'invalid_response'],
            [200, ['choices' => [['finish_reason' => 'length', 'message' => ['role' => 'assistant', 'content' => 'private-body-marker']]]], 'invalid_response'],
            [200, ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'private-body-marker']]]], 'invalid_json'],
        ];
        foreach (['support_selector', 'intent_router', 'answer_synthesis'] as $stage) {
            foreach ($cases as $index => [$status, $body, $type]) {
                yield $stage.'/'.$index => [$stage, $status, $body, $type];
            }
        }
    }

    #[DataProvider('failures')]
    public function test_failures_log_only_safe_metadata_without_retry(string $stage, int $status, array|string $body, string $type): void
    {
        Http::fake(['api.groq.com/*' => Http::response($body, $status)]);
        $this->callStage($stage);
        $this->assertDiagnostic($stage, $status, $type);
        Http::assertSentCount(1);
    }

    public static function connectionFailures(): iterable
    {
        foreach (['support_selector', 'intent_router', 'answer_synthesis'] as $stage) {
            yield $stage.'/timeout' => [$stage, 28, 'timeout'];
            yield $stage.'/dns' => [$stage, 6, 'connection_failed'];
        }
    }

    #[DataProvider('connectionFailures')]
    public function test_timeout_is_distinguished_using_transport_metadata_only(string $stage, int $errno, string $type): void
    {
        $attempts = 0;
        Http::fake(['api.groq.com/*' => function () use ($errno, &$attempts) {
            $attempts++;
            throw new ConnectionException('private-exception-marker private-key-marker', 0,
                $errno === 28 ? new ConnectTimeoutException('private-body-marker', new Request('POST', 'https://api.groq.com/private-url')) : new ConnectException('private-body-marker', new Request('POST', 'https://api.groq.com/private-url')));
        }]);
        $this->callStage($stage);
        $this->assertDiagnostic($stage, null, $type);
        $this->assertSame(1, $attempts);
    }

    private function assertDiagnostic(string $stage, ?int $status, string $type): void
    {
        Log::shouldHaveReceived('warning')->once()->withArgs(function ($event, $context) use ($stage, $status, $type): bool {
            $this->assertSame('ai_chat.groq_failed', $event);
            $this->assertSame(['stage', 'http_status', 'error_type', 'duration_ms'], array_keys($context));
            $this->assertSame($stage, $context['stage']);
            $this->assertSame($status, $context['http_status']);
            $this->assertSame($type, $context['error_type']);
            $this->assertIsInt($context['duration_ms']);
            $this->assertGreaterThanOrEqual(0, $context['duration_ms']);
            foreach (['private-', 'Authorization', 'Bearer', 'api.groq.com'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode([$event, $context]));
            }

            return true;
        });
    }
}
