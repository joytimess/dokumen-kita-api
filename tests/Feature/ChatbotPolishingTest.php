<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PersonalDocumentReadTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatbotPolishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.groq.api_key' => 'test-key', 'chatbot.model' => 'test-model', 'chatbot.per_minute' => 60]);
        Http::preventStrayRequests();
    }

    private function completion(array $content): array
    {
        return ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => json_encode($content)]]]];
    }

    private function context(User $user, string $topic): string
    {
        return Crypt::encryptString(json_encode(['user' => $user->guid, 'expires' => now()->addMinutes(30)->timestamp, 'locale' => 'id', 'tool' => 'lookup_support_guidance', 'support_topic' => $topic]));
    }

    public static function smallTalk(): array
    {
        return [
            ['Halo!', 'en', 'id', 'Halo! Ada yang bisa saya bantu?'],
            ['Hai 👋', 'id', 'id', 'Halo! Ada yang bisa saya bantu?'],
            ['Makasih!', 'en', 'id', 'Sama-sama!'],
            ['Terima kasih', 'id', 'id', 'Sama-sama!'],
            ['Oke.', 'en', 'id', 'Siap!'],
            ['Hello!', 'id', 'en', 'Hello! How can I help?'],
            ['Hi', 'id', 'en', 'Hello! How can I help?'],
            ['Thanks!', 'id', 'en', "You're welcome!"],
            ['Thank you', 'en', 'en', "You're welcome!"],
            ['Okay', 'id', 'en', 'Got it!'],
        ];
    }

    #[DataProvider('smallTalk')]
    public function test_small_talk_is_local_read_only_and_preserves_signed_context(string $message, string $requestedLocale, string $locale, string $reply): void
    {
        $user = User::factory()->create();
        $context = $this->context($user, 'sign_document');
        $this->mock(PersonalDocumentReadTools::class)->shouldNotReceive('execute');
        Http::fake();
        $writes = [];
        DB::listen(function ($event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => $message, 'locale' => $requestedLocale, 'context' => $context])
            ->assertOk()->assertJsonPath('data.reply', $reply)->assertJsonPath('data.locale', $locale)
            ->assertJsonPath('data.context', $context)->assertJsonPath('data.results', null)->assertJsonCount(0, 'data.references');
        Http::assertNothingSent();
        $this->assertSame([], $writes);
    }

    public static function mixedMessages(): array
    {
        return [['Halo, dokumen saya apa saja?', 'id'], ['Hello, what documents do I have?', 'en'], ['Thanks, what documents do I have?', 'en'], ['Oke, dokumen saya apa saja?', 'id']];
    }

    #[DataProvider('mixedMessages')]
    public function test_small_talk_with_a_question_continues_intent_processing(string $message, string $locale): void
    {
        $user = User::factory()->create();
        $this->mock(PersonalDocumentReadTools::class)->shouldReceive('execute')->once()->withArgs(fn ($actor, $tool, $args) => $actor->is($user) && $tool === 'search_my_documents' && $args === [])
            ->andReturn(['items' => [], 'references' => []]);
        Http::fake(['api.groq.com/*' => function ($request) {
            if (str_contains($request['messages'][0]['content'], 'Route a Dokumen-Kita')) {
                return Http::response($this->completion(['tool' => 'search_my_documents', 'arguments' => (object) []]));
            }
            $payload = json_decode($request['messages'][1]['content'], true);

            return Http::response($this->completion(['sentences' => array_map(fn ($fact) => ['fact_id' => $fact['id'], 'text' => $fact['meaning']], $payload['facts'])]));
        }]);
        $this->actingAs($user)->postJson('/api/v1/ai/chat', compact('message', 'locale'))->assertOk()
            ->assertJsonPath('data.intent', 'search_my_documents')->assertJsonPath('data.results.items', []);
        Http::assertSentCount(2);
    }

    public static function emailConversations(): array
    {
        return [
            ['id', 'Apakah saya akan mendapatkan email?', 'Kalau emailnya belum masuk?', 'Iya, kamu akan menerima email undangan saat giliran tanda tanganmu aktif.', 'Coba cek folder spam. Kalau masih belum ada dan giliranmu sudah aktif, hubungi pemilik dokumen untuk memeriksa atau mengirim ulang undangan.'],
            ['en', 'Will I receive an email?', "What if the email hasn't arrived?", 'Yes, you will receive an invitation email when your signing turn becomes active.', 'Check your spam folder. If it is still missing and your turn is active, ask the document owner to check or resend the invitation.'],
        ];
    }

    #[DataProvider('emailConversations')]
    public function test_email_follow_ups_are_concise_and_local_but_unknown_delivery_guarantees_are_not_invented(string $locale, string $receiving, string $missing, string $receivingReply, string $missingReply): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $context = $this->context($user, 'sign_document');
        Http::fake(['api.groq.com/*' => function ($request) {
            return Http::response($this->completion(str_contains($request['messages'][0]['content'], 'Route a Dokumen-Kita')
                ? ['tool' => 'clarify', 'arguments' => (object) []] : ['answer_id' => null]));
        }]);
        $response = $this->postJson('/api/v1/ai/chat', ['message' => $receiving, 'locale' => $locale, 'context' => $context])->assertOk()->assertJsonPath('data.reply', $receivingReply);
        $response = $this->postJson('/api/v1/ai/chat', ['message' => $missing, 'locale' => $locale, 'context' => $response->json('data.context')])->assertOk()->assertJsonPath('data.reply', $missingReply);
        $context = $response->json('data.context');
        Http::assertNothingSent();
        $this->postJson('/api/v1/ai/chat', ['message' => 'Is delivery guaranteed within three seconds?', 'context' => $context])->assertOk()
            ->assertJsonPath('data.context', $context)->assertJsonPath('data.results', null)->assertJsonCount(0, 'data.references');
        Http::assertSentCount(2);
    }

    public function test_valid_topic_switch_uses_one_router_call_and_detail_is_only_returned_when_requested(): void
    {
        $user = User::factory()->create();
        $context = $this->context($user, 'sign_document');
        Http::fake(['api.groq.com/*' => Http::response($this->completion(['tool' => 'lookup_support_guidance', 'arguments' => ['topic' => 'registration']]))]);
        $response = $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => 'Jelaskan proses daftar akun secara detail', 'context' => $context])->assertOk();
        $entries = array_column(require resource_path('knowledge/support.php'), null, 'id');
        $response->assertJsonPath('data.reply', $entries['registration']['answer']['id']);
        Http::assertSentCount(1);
        $response = $this->postJson('/api/v1/ai/chat', ['message' => 'Apakah saya akan mendapatkan email?', 'context' => $response->json('data.context')])->assertOk()
            ->assertJsonPath('data.reply', 'Iya, setelah mendaftar kamu akan menerima email verifikasi akun.');
        $this->assertStringNotContainsString('undangan', $response->json('data.reply'));
        Http::assertSentCount(1);
    }

    public function test_greeting_does_not_bypass_mutation_refusal_or_user_bound_context(): void
    {
        $owner = User::factory()->create();
        $context = $this->context($owner, 'sign_document');
        Http::fake();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/ai/chat', ['message' => 'Halo!', 'context' => $context])->assertOk()->assertJsonPath('data.context', null);
        $this->postJson('/api/v1/ai/chat', ['message' => 'Halo, hapus dokumen saya'])->assertOk()->assertJsonPath('data.intent', 'refuse')->assertJsonPath('data.results', null);
        Http::assertNothingSent();
    }

    public static function focusedGuidance(): array
    {
        return [
            ['How do I log out of my account?', 'login', 'en', 'Use logout to end your session.'],
            ['How do I resend a signing invitation?', 'publish_invitation', 'en', 'Resending is available for the recipient whose turn is active; the previous invitation link is revoked.'],
            ['Bagaimana konfirmasi baca sebelum tanda tangan?', 'sign_document', 'id', 'Saat giliran Anda siap, baca preview dokumen dan konfirmasikan telah membaca snapshot tersebut.'],
            ['How do I delete a saved signature?', 'signature_assets', 'en', 'An asset already used to sign cannot be deleted.'],
        ];
    }

    #[DataProvider('focusedGuidance')]
    public function test_one_part_of_a_procedure_does_not_repeat_the_whole_procedure(string $message, string $topic, string $locale, string $reply): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->completion(['answer_id' => $topic]))]);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/ai/chat', compact('message', 'locale'))->assertOk()->assertJsonPath('data.reply', $reply);
        Http::assertSentCount(1);
    }
}
