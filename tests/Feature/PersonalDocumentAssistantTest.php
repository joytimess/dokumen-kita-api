<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignerAssignment;
use App\Models\User;
use App\Services\PersonalDocumentReadTools;
use App\Services\SupportKnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Tests\TestCase;

class PersonalDocumentAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.groq.api_key' => 'test-key', 'chatbot.model' => 'test-model', 'chatbot.per_minute' => 60]);
        Http::preventStrayRequests();
    }

    private function plan(string $tool, array $arguments = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.groq.com/*' => function ($request) use ($tool, $arguments) {
            $payload = json_decode($request['messages'][1]['content'], true);
            $content = isset($payload['facts'])
                ? ['sentences' => array_map(fn ($fact) => ['fact_id' => $fact['id'], 'text' => $fact['meaning']], $payload['facts'])]
                : ['tool' => $tool, 'arguments' => (object) $arguments];

            return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => json_encode($content)]]]]);
        }]);
    }

    private function document(User $owner, string $title = 'Kontrak ABCD', string $status = 'in_progress', int $version = 1, ?Document $document = null): DocumentVersion
    {
        $document ??= Document::factory()->create(['owner_guid' => $owner->guid, 'title' => $title, 'status' => $status]);

        return DocumentVersion::factory()->create(['document_guid' => $document->guid, 'version_number' => $version, 'status' => $status, 'editor_data_json' => ['blocks' => [['private-content-secret']]], 'source_path' => 'private-blob-secret.pdf']);
    }

    private function execute(User $user, string $tool, array $arguments = []): array
    {
        return app(PersonalDocumentReadTools::class)->execute($user, $tool, $arguments);
    }

    public function test_count_filters_and_literal_search_are_owner_scoped_and_paginated(): void
    {
        $owner = User::factory()->create();
        $this->document($owner, 'É 合同 100%_ABC!');
        $this->document($owner, 'é 合同 100%_abc!');
        $hidden = $this->document($owner, 'Hidden', 'draft');
        $hidden->document->forceFill(['hidden_at' => now()])->save();
        $this->document(User::factory()->create(), 'É 合同 100%_ABC!');
        $this->assertSame(2, $this->execute($owner, 'count_my_documents')['count']);
        $this->assertSame(1, $this->execute($owner, 'count_my_documents', ['visibility' => 'hidden', 'status' => 'draft'])['count']);
        $result = $this->execute($owner, 'search_my_documents', ['query' => '合同 100%_abC!', 'limit' => 1]);
        $this->assertCount(1, $result['items']);
        $this->assertTrue($result['pagination']['has_more']);
        $next = $this->execute($owner, 'search_my_documents', ['query' => '合同 100%_abC!', 'limit' => 1, 'page' => 2]);
        $this->assertFalse($next['pagination']['has_more']);
        $this->assertNotSame($result['items'][0]['document_ref'], $next['items'][0]['document_ref']);
        $this->assertSame([], $this->execute($owner, 'search_my_documents', ['query' => 'missing'])['items']);
        $this->assertSame(0, $this->execute(User::factory()->create(), 'count_my_documents')['count']);
    }

    public static function ownerDetailTools(): array
    {
        return array_map(fn ($tool) => [$tool], ['get_my_document', 'get_document_signers', 'get_document_signing_progress']);
    }

    #[DataProvider('ownerDetailTools')]
    public function test_every_owner_detail_tool_denies_other_accounts_and_signers(string $tool): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->document($owner);
        SignerAssignment::factory()->create(['version_guid' => $version->guid, 'user_guid' => $signer->guid]);
        $args = ['document_ref' => $version->document_guid];
        $this->assertArrayNotHasKey('error', $this->execute($owner, $tool, $args));
        $this->assertSame(['error' => 'not_found'], $this->execute($signer, $tool, $args));
        $this->assertSame(['error' => 'not_found'], $this->execute(User::factory()->create(), $tool, $args));
    }

    public function test_owner_progress_and_recipients_use_current_version_and_real_workflow_state(): void
    {
        $owner = User::factory()->create();
        $old = $this->document($owner, status: 'superseded');
        SignerAssignment::factory()->create(['version_guid' => $old->guid, 'status' => 'ready', 'recipient_email' => 'obsolete@example.test']);
        $current = $this->document($owner, version: 2, document: $old->document);
        foreach (['signed', 'ready', 'waiting'] as $index => $status) {
            SignerAssignment::factory()->create(['version_guid' => $current->guid, 'sequence_number' => $index + 1, 'status' => $status]);
        }
        $args = ['document_ref' => $old->document_guid];
        $result = $this->execute($owner, 'get_document_signing_progress', $args);
        $this->assertSame(2, $result['version']['number']);
        $this->assertSame(3, $result['progress']['total']);
        $this->assertSame(1, $result['progress']['signed']);
        $this->assertSame(2, $result['progress']['active_sequence']);
        $this->assertStringNotContainsString('obsolete@example.test', json_encode($this->execute($owner, 'get_document_signers', $args)));
        $current->update(['status' => 'revision_requested']);
        $this->assertNull($this->execute($owner, 'get_document_signing_progress', $args)['progress']['active_sequence']);
    }

    public function test_invitations_only_use_account_binding_and_do_not_disclose_other_recipients(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->document($owner);
        $mine = SignerAssignment::factory()->create(['version_guid' => $version->guid, 'user_guid' => $signer->guid, 'status' => 'ready']);
        $unclaimed = SignerAssignment::factory()->create(['version_guid' => $version->guid, 'recipient_email' => $signer->email, 'normalized_recipient_email' => $signer->email, 'sequence_number' => 2]);
        $result = $this->execute($signer, 'list_my_invitations');
        $this->assertCount(1, $result['items']);
        $this->assertTrue($result['items'][0]['eligible_to_sign']);
        $this->assertStringNotContainsString('recipient_email', json_encode($result));
        $this->assertStringNotContainsString($unclaimed->guid, json_encode($result));
        $this->assertSame(['error' => 'not_found'], $this->execute($owner, 'get_my_invitation', ['assignment_ref' => $mine->guid]));
        $this->assertSame(['error' => 'not_found'], $this->execute($signer, 'get_my_invitation', ['assignment_ref' => $unclaimed->guid]));
        $this->document($owner, version: 2, document: $version->document);
        $historical = $this->execute($signer, 'get_my_invitation', ['assignment_ref' => $mine->guid]);
        $this->assertFalse($historical['items'][0]['current_version']);
        $this->assertFalse($historical['items'][0]['eligible_to_sign']);
    }

    public static function invitationStates(): array
    {
        return [['in_progress', 'waiting', false, false], ['in_progress', 'ready', true, true], ['completed', 'signed', false, true], ['revision_requested', 'ready', false, true], ['rejected', 'rejected', false, true], ['superseded', 'ready', false, true]];
    }

    #[DataProvider('invitationStates')]
    public function test_invitation_eligibility_and_viewer_references_match_existing_rules(string $versionStatus, string $status, bool $eligible, bool $link): void
    {
        $user = User::factory()->create();
        $version = $this->document(User::factory()->create(), status: $versionStatus);
        SignerAssignment::factory()->create(['version_guid' => $version->guid, 'user_guid' => $user->guid, 'status' => $status]);
        $result = $this->execute($user, 'list_my_invitations');
        $this->assertSame($eligible, $result['items'][0]['eligible_to_sign']);
        $this->assertCount($link ? 1 : 0, $result['references']);
    }

    public function test_follow_up_is_account_bound_reauthorized_and_uses_fresh_status(): void
    {
        $user = User::factory()->create();
        $version = $this->document($user);
        $this->plan('search_my_documents', ['query' => 'ABCD']);
        $first = $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => 'Cari kontrak ABCD'])->assertOk()->assertJsonCount(1, 'data.references');
        $context = $first->json('data.context');
        $this->plan('get_my_document');
        $version->document->update(['status' => 'completed']);
        $this->postJson('/api/v1/ai/chat', ['message' => 'Yang tadi?', 'context' => $context])->assertOk()->assertJsonPath('data.results.status', 'completed');
        $version->document->update(['owner_guid' => User::factory()->create()->guid]);
        $this->postJson('/api/v1/ai/chat', ['message' => 'Yang tadi?', 'context' => $context])->assertOk()->assertJsonPath('data.results', null)->assertJsonCount(0, 'data.references');
        $this->actingAs(User::factory()->create())->postJson('/api/v1/ai/chat', ['message' => 'Yang tadi?', 'context' => $context])->assertOk()->assertJsonPath('data.results', null);
        $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => 'Yang tadi?', 'context' => 'forged'])->assertOk()->assertJsonPath('data.results', null);
        $this->travel(31)->minutes();
        $this->postJson('/api/v1/ai/chat', ['message' => 'Yang tadi?', 'context' => $context])->assertOk()->assertJsonPath('data.results', null);
    }

    public function test_ambiguous_search_requires_choice_and_pagination_retains_filters(): void
    {
        $user = User::factory()->create();
        $a = $this->document($user);
        $b = $this->document($user);
        $this->document($user, 'Other');
        $this->plan('get_document_signers', ['query' => 'ABCD']);
        $first = $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => 'Penerima kontrak ABCD?'])->assertOk()->assertJsonCount(2, 'data.results.items');
        $this->plan('get_my_document', ['selection' => 1]);
        $clarification = $this->postJson('/api/v1/ai/chat', ['message' => 'Yang tadi', 'context' => $first->json('data.context')])->assertOk()->assertJsonPath('data.results', null)->assertJsonCount(0, 'data.references');
        $this->plan('get_my_document', ['selection' => 2]);
        $this->postJson('/api/v1/ai/chat', ['message' => 'Yang kedua', 'context' => $clarification->json('data.context')])->assertOk()->assertJsonPath('data.results.document_ref', $a->document_guid);
        $this->plan('search_my_documents', ['query' => 'ABCD', 'limit' => 1]);
        $page = $this->postJson('/api/v1/ai/chat', ['message' => 'Cari ABCD'])->assertOk()->assertJsonPath('data.results.items.0.document_ref', $b->document_guid);
        $this->plan('search_my_documents', ['page' => 2]);
        $this->postJson('/api/v1/ai/chat', ['message' => 'Halaman 2', 'context' => $page->json('data.context')])->assertOk()->assertJsonPath('data.results.items.0.document_ref', $a->document_guid)->assertJsonPath('data.results.pagination.has_more', false);
    }

    public function test_private_results_are_not_sent_to_provider_and_tools_only_select(): void
    {
        $user = User::factory()->create(['email' => 'private-account@example.test']);
        $version = $this->document($user, 'private-title-secret');
        SignerAssignment::factory()->create(['version_guid' => $version->guid, 'recipient_email' => 'private-recipient@example.test']);
        $this->plan('get_document_signers', ['document_ref' => $version->document_guid]);
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $response = $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => 'Penerima dokumen?', 'history' => [['role' => 'assistant', 'content' => 'private-history-secret']]])->assertOk();
        $this->assertStringContainsString('private-recipient@example.test', $response->getContent());
        foreach (Http::recorded() as [$request]) {
            $payload = json_encode($request->data());
            $this->assertStringContainsString('JSON', $request['messages'][0]['content']);
            foreach (['private-title-secret', 'private-account@example.test', 'private-recipient@example.test', 'private-history-secret', 'private-content-secret', 'private-blob-secret', 'test-key'] as $secret) {
                $this->assertStringNotContainsString($secret, $payload);
            }

        }
        Http::assertSentCount(2);
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql))));
        $this->assertStringNotContainsString('editor_data_json', implode(' ', $queries));
        $this->assertStringNotContainsString('private-blob-secret', $response->getContent());
    }

    public static function invalidTools(): array
    {
        return [['delete_document', []], ['count_my_documents', ['user_id' => 1]], ['count_my_documents', ['status' => 'cancelled']], ['search_my_documents', ['limit' => 21]], ['search_my_documents', ['limit' => '']], ['search_my_documents', ['page' => '']], ['search_my_documents', ['visibility' => '']], ['search_my_documents', ['query' => ['sql' => 'SELECT *']]], ['search_my_documents', ['page' => 0]], ['get_my_document', ['document_ref' => 'invalid']], ['list_my_invitations', ['email' => 'foreign@example.test']], ['get_my_invitation', []]];
    }

    #[DataProvider('invalidTools')]
    public function test_dispatcher_rejects_unknown_tools_and_invalid_or_identity_arguments(string $tool, array $arguments): void
    {
        $this->assertSame(['error' => 'invalid_tool'], $this->execute(User::factory()->create(), $tool, $arguments));
    }

    #[DataProviderExternal(AiChatTest::class, 'providerFailures')]
    public function test_invalid_provider_responses_never_execute_tools_or_expose_output(int $status, array|string $body): void
    {
        Http::fake(['api.groq.com/*' => Http::response($body, $status)]);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/ai/chat', ['message' => 'Dokumen gue ada berapa?'])->assertOk()->assertJsonPath('data.results', null)->assertJsonCount(0, 'data.references');
        Http::assertSentCount(1);
    }

    public function test_timeout_refusal_and_content_scope_are_safe(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['api.groq.com/*' => Http::failedConnection('private-provider-secret')]);
        $this->postJson('/api/v1/ai/chat', ['message' => 'Dokumen gue ada berapa?'])->assertOk()->assertJsonPath('data.reply', app(SupportKnowledgeBase::class)->unavailable('id'));
        $this->plan('count_my_documents');
        foreach (['Tolong langsung sign dokumen', 'Hapus dokumen', 'Kirim ulang undangan', 'Mark read'] as $message) {
            $this->postJson('/api/v1/ai/chat', compact('message'))->assertOk()->assertJsonPath('data.intent', 'refuse')->assertJsonPath('data.results', null);
        }
        Http::assertNothingSent();
        $this->plan('content_unavailable');
        $this->postJson('/api/v1/ai/chat', ['message' => 'Apa isi klausul kontrak?', 'locale' => 'en'])->assertOk()->assertJsonPath('data.intent', 'content_unavailable')->assertJsonPath('data.locale', 'id');
    }

    public static function invalidPlans(): array
    {
        return [
            ['{"tool":"delete_document","arguments":{}}'],
            ['{"tool":"private-provider-secret","arguments":{}}'],
            ['{"tool":"count_my_documents","arguments":{"user_id":1}}'],
            ['{"tool":"count_my_documents","arguments":{"selection":1}}'],
            ['{"tool":"search_my_documents","arguments":{"limit":21}}'],
            ['{"tool":"search_my_documents","arguments":[]}'],
            ['{"tool":"get_my_document","arguments":{"selection":"1"}}'],
            ['{"tool":"count_my_documents","arguments":{},"focus":"execute_sql"}'],
            ['{"tool":"get_document_signers","arguments":{},"signer_sequence":0}'],
            ['{"tool":"lookup_support_guidance","arguments":{"topic":[]}}'],
            ['{"tool":"count_my_documents","arguments":{},"reply":"private-provider-secret"}'],
            ['[{"tool":"count_my_documents","arguments":{}},{"tool":"get_my_document","arguments":{}}]'],
        ];
    }

    #[DataProvider('invalidPlans')]
    public function test_forged_provider_plans_do_not_return_data_or_untrusted_text(string $content): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $content]]]])]);
        $response = $this->actingAs(User::factory()->create())->postJson('/api/v1/ai/chat', ['message' => 'Dokumen saya?'])->assertOk()->assertJsonPath('data.results', null)->assertJsonCount(0, 'data.references');
        $this->assertStringNotContainsString('private-provider-secret', $response->getContent());
    }

    public function test_mixed_roles_revoked_assignment_and_signer_follow_up_stay_scoped(): void
    {
        $user = User::factory()->create();
        $this->document($user, 'My document');
        $invited = $this->document(User::factory()->create(), 'Invited document');
        $assignment = SignerAssignment::factory()->create(['version_guid' => $invited->guid, 'user_guid' => $user->guid, 'status' => 'ready']);
        $this->assertSame(1, $this->execute($user, 'count_my_documents')['count']);
        $this->plan('list_my_invitations');
        $first = $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => 'Undangan gue mana?'])->assertOk()->assertJsonCount(1, 'data.results.items');
        $this->plan('get_document_signing_progress');
        $next = $this->postJson('/api/v1/ai/chat', ['message' => 'Dokumen itu giliran siapa?', 'context' => $first->json('data.context')])->assertOk()->assertJsonPath('data.intent', 'get_my_invitation')->assertJsonPath('data.results.items.0.eligible_to_sign', true);
        $this->assertStringNotContainsString('recipient_email', $next->getContent());
        $assignment->update(['user_guid' => null]);
        $this->plan('get_my_invitation');
        $this->postJson('/api/v1/ai/chat', ['message' => 'Yang tadi?', 'context' => $next->json('data.context')])->assertOk()->assertJsonPath('data.results', null);
    }

    public function test_typed_emails_invitation_urls_and_tokens_are_redacted_before_routing(): void
    {
        $this->plan('clarify');
        $this->actingAs(User::factory()->create())->postJson('/api/v1/ai/chat', ['message' => 'Cari undangan recipient@example.test https://app.test/invitations/claim?token='.str_repeat('a', 64).' gsk_PRIVATE_SECRET'])->assertOk();
        Http::assertSent(function ($request): bool {
            $payload = json_encode($request->data());
            foreach (['recipient@example.test', 'https://app.test', str_repeat('a', 64), 'gsk_PRIVATE_SECRET'] as $secret) {
                $this->assertStringNotContainsString($secret, $payload);
            }

            return true;
        });
    }

    public function test_unknown_paraphrases_can_only_return_verified_guidance(): void
    {
        $entries = array_column(require resource_path('knowledge/support.php'), null, 'id');
        $this->plan('lookup_support_guidance', ['topic' => 'sign_document']);
        $message = 'Harus baca semua halaman sebelum menaruh TTD?';
        $this->actingAs(User::factory()->create())->postJson('/api/v1/ai/chat', ['message' => $message, 'locale' => 'en'])->assertOk()->assertJsonPath('data.reply', app(SupportKnowledgeBase::class)->answer($entries['sign_document'], $message, 'id'))->assertJsonPath('data.results', null);
    }

    public static function documentVolumes(): array
    {
        return [[1], [10], [100]];
    }

    #[DataProvider('documentVolumes')]
    public function test_read_query_budget_stays_constant_as_document_volume_grows(int $volume): void
    {
        $owner = User::factory()->create();
        for ($i = 0; $i < $volume; $i++) {
            $version = $this->document($owner, 'Volume '.$i);
            SignerAssignment::factory()->create(['version_guid' => $version->guid, 'user_guid' => $owner->guid, 'status' => 'ready']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame($volume, $this->execute($owner, 'count_my_documents')['count']);
        $documents = $this->execute($owner, 'search_my_documents');
        $invitations = $this->execute($owner, 'list_my_invitations');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(min(10, $volume), $documents['items']);
        $this->assertCount(min(10, $volume), $invitations['items']);
        $this->assertLessThanOrEqual(8, $queries, 'Read queries must remain bounded, without N+1.');
    }
}
