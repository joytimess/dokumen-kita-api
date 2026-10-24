<?php

namespace Tests\Feature;

use App\Http\Resources\DocumentVersionResource;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignerAssignment;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResourceQueryOptimizationTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public static function versionCases(): iterable
    {
        foreach ([1, 10, 100] as $count) {
            foreach (['owner', 'ready', 'waiting', 'outsider'] as $role) {
                yield "$role/$count" => [$count, $role];
            }
        }
    }

    public static function pageSizes(): iterable
    {
        foreach ([1, 10, 100] as $count) {
            yield (string) $count => [$count];
        }
    }

    public static function historyCases(): iterable
    {
        foreach ([1, 10, 100] as $count) {
            foreach (['owner', 'recipient', 'outsider', 'mixed'] as $role) {
                yield "$role/$count" => [$count, $role];
            }
        }
    }

    #[DataProvider('versionCases')]
    public function test_preloaded_version_resources_do_not_query_per_item(int $count, string $role): void
    {
        [$owner, $recipient, $document] = $this->versions($count, $role === 'waiting' ? 'waiting' : 'ready');
        $viewer = match ($role) {
            'owner' => $owner,
            'outsider' => User::factory()->create(),
            default => $recipient,
        };
        $this->actingAs($viewer);
        $request = Request::create('/api/v1/documents/'.$document->guid.'/versions');
        $request->setUserResolver(fn () => $viewer);
        $versions = $document->versions()->with('document.latestVersion:guid,document_guid,version_number', 'assignments')->orderBy('version_number')->get();

        [$data, $queries] = $this->selects(fn () => DocumentVersionResource::collection($versions)->response($request)->getData(true));

        $this->assertCount($count, $data['data']);
        foreach ($data['data'] as $index => $item) {
            $this->assertSame($index === $count - 1, $item['is_current']);
            $this->assertSame(in_array($role, ['owner', 'ready'], true), array_key_exists('content_hash', $item));
            $this->assertSame($role === 'owner', array_key_exists('editor_data', $item));
            $this->assertCount($role === 'outsider' ? 0 : 1, $item['assignments']);
        }
        $this->assertCount(0, $queries, "$role/$count resource SELECTs: ".count($queries));
    }

    #[DataProvider('versionCases')]
    public function test_version_listing_has_bounded_queries_and_preserves_access(int $count, string $role): void
    {
        [$owner, $recipient, $document] = $this->versions($count, $role === 'waiting' ? 'waiting' : 'ready');
        $viewer = match ($role) {
            'owner' => $owner,
            'outsider' => User::factory()->create(),
            default => $recipient,
        };
        $this->actingAs($viewer);

        [$response, $queries] = $this->selects(fn () => $this->getJson("/api/v1/documents/{$document->guid}/versions?per_page=$count"));

        if ($role === 'outsider') {
            $response->assertNotFound();

            return;
        }
        $response->assertOk()->assertJsonCount($count, 'data')->assertJsonPath('meta.total', $count)
            ->assertJsonPath('data.0.is_current', true);
        if ($count > 1) {
            $response->assertJsonPath('data.1.is_current', false);
        }
        if ($role === 'waiting') {
            $response->assertJsonMissingPath('data.0.content_hash');
        } else {
            $response->assertJsonPath('data.0.content_hash', str_repeat('a', 64));
        }
        $this->assertLessThanOrEqual(11, count($queries), "$role/$count listing SELECTs: ".count($queries));
    }

    #[DataProvider('pageSizes')]
    public function test_document_listing_reuses_the_actual_latest_version(int $count): void
    {
        $owner = User::factory()->create();
        for ($index = 0; $index < $count; $index++) {
            $document = Document::factory()->create(['owner_guid' => $owner->guid]);
            DocumentVersion::factory()->create(['document_guid' => $document->guid]);
            DocumentVersion::factory()->create(['document_guid' => $document->guid, 'version_number' => 2]);
        }
        $this->actingAs($owner);

        [$response, $queries] = $this->selects(fn () => $this->getJson("/api/v1/documents?per_page=$count"));

        $response->assertOk()->assertJsonCount($count, 'data')->assertJsonPath('meta.total', $count);
        foreach ($response->json('data') as $item) {
            $this->assertSame(2, $item['current_version']['version_number']);
            $this->assertTrue($item['current_version']['is_current']);
            $this->assertArrayNotHasKey('assignments', $item['current_version']);
        }
        $this->assertLessThanOrEqual(5, count($queries), "$count document SELECTs: ".count($queries));
    }

    #[DataProvider('historyCases')]
    public function test_history_authorization_is_bounded_for_repeated_and_distinct_documents(int $count, string $role): void
    {
        $owner = User::factory()->create();
        $viewer = $role === 'owner' ? $owner : User::factory()->create();
        $expected = [];
        $document = null;
        for ($index = 0; $index < $count; $index++) {
            if ($document === null || $role === 'mixed') {
                $document = Document::factory()->create(['owner_guid' => $owner->guid]);
                $version = DocumentVersion::factory()->create(['document_guid' => $document->guid]);
                $access = $role === 'owner' || $role === 'recipient' || ($role === 'mixed' && $index % 2 === 0);
                if ($access && $role !== 'owner') {
                    SignerAssignment::factory()->create(['version_guid' => $version->guid, 'user_guid' => $viewer->guid, 'status' => 'waiting']);
                    DocumentVersion::factory()->create(['document_guid' => $document->guid, 'version_number' => 2]);
                }
            }
            $event = AuditEvent::factory()->create([
                'document_guid' => $document->guid, 'version_guid' => $version->guid,
                'actor_guid' => $viewer->guid, 'action' => 'assignment.signed',
            ]);
            $expected[$event->guid] = [$access, $document->guid, $version->guid];
        }
        AuditEvent::factory()->create([
            'document_guid' => $document->guid, 'version_guid' => $version->guid,
            'actor_guid' => User::factory()->create()->guid, 'action' => 'assignment.signed',
        ]);
        $this->actingAs($viewer);

        [$response, $queries] = $this->selects(fn () => $this->getJson("/api/v1/me/history?per_page=$count"));

        $response->assertOk()->assertJsonCount($count, 'data')->assertJsonPath('meta.total', $count);
        foreach ($response->json('data') as $item) {
            [$access, $documentGuid, $versionGuid] = $expected[$item['guid']];
            $this->assertSame($access, $item['can_access_document']);
            $this->assertSame($access ? $documentGuid : null, $item['document']['guid']);
            $this->assertSame($access ? $versionGuid : null, $item['version_guid']);
            $this->assertArrayNotHasKey('recipientMemberships', $item['document']);
        }
        $this->assertLessThanOrEqual(6, count($queries), "$role/$count history SELECTs: ".count($queries));
    }

    public static function assignmentStatuses(): iterable
    {
        foreach (['waiting', 'ready', 'signed', 'revision_requested', 'rejected', 'cancelled'] as $status) {
            yield $status => [$status, $status !== 'waiting' && $status !== 'cancelled'];
        }
    }

    #[DataProvider('assignmentStatuses')]
    public function test_loaded_assignments_preserve_pdf_policy_and_first_signer_semantics(string $status, bool $allowed): void
    {
        [$owner, $recipient, $document] = $this->versions(1, $status);
        $version = $document->versions()->firstOrFail();
        SignerAssignment::factory()->create([
            'version_guid' => $version->guid, 'user_guid' => $recipient->guid,
            'sequence_number' => 2, 'status' => 'ready',
        ]);
        $cold = $version->fresh('document');
        $warm = $version->fresh(['document', 'assignments']);
        $warm->setRelation('assignments', $warm->assignments->reverse());
        $outsider = User::factory()->create();

        foreach ([$owner, $recipient, $outsider] as $viewer) {
            $expected = Gate::forUser($viewer)->inspect('viewPdf', $cold);
            [$actual, $queries] = $this->selects(fn () => Gate::forUser($viewer)->inspect('viewPdf', $warm));
            $this->assertSame($expected->allowed(), $actual->allowed());
            $this->assertSame($expected->status(), $actual->status());
            $this->assertCount(0, $queries);
        }
        $this->assertSame($allowed, Gate::forUser($recipient)->allows('viewPdf', $warm));
        $this->actingAs($recipient)->getJson("/api/v1/versions/{$version->guid}")->assertOk();
        $this->actingAs($recipient)->patchJson("/api/v1/versions/{$version->guid}", ['title' => 'Denied'])->assertForbidden();
        if (! $allowed) {
            $this->getJson("/api/v1/versions/{$version->guid}/pdf")->assertForbidden();
        }
        $this->actingAs($outsider)->getJson("/api/v1/versions/{$version->guid}")->assertNotFound();
        $this->getJson("/api/v1/versions/{$version->guid}/pdf")->assertNotFound();
        $this->patchJson("/api/v1/versions/{$version->guid}", ['title' => 'Denied'])->assertNotFound();
    }

    public function test_batch_current_version_matches_single_resource_json_and_unassigned_newer_versions(): void
    {
        [$owner, $recipient, $document] = $this->versions(1, 'ready');
        $historical = $document->versions()->firstOrFail();
        $latest = DocumentVersion::factory()->create(['document_guid' => $document->guid, 'version_number' => 2]);

        foreach ([$owner, $recipient] as $viewer) {
            $this->actingAs($viewer);
            $request = Request::create('/api/v1/versions/'.$historical->guid);
            $request->setUserResolver(fn () => $viewer);
            foreach ([$historical, $latest] as $version) {
                $cold = $version->fresh(['document', 'assignments']);
                $warm = $version->fresh(['document.latestVersion:guid,document_guid,version_number', 'assignments']);
                $expected = (new DocumentVersionResource($cold))->response($request)->getData(true);
                [$actual, $queries] = $this->selects(fn () => (new DocumentVersionResource($warm))->response($request)->getData(true));
                $this->assertSame($expected, $actual);
                $this->assertSame($version->is($latest), $actual['data']['is_current']);
                $this->assertCount(0, $queries);
            }
        }
        $this->actingAs($recipient)->getJson("/api/v1/documents/{$document->guid}/versions")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.guid', $historical->guid)
            ->assertJsonPath('data.0.is_current', false);
        $this->getJson("/api/v1/versions/{$latest->guid}")->assertNotFound();
        $this->actingAs($owner)->getJson("/api/v1/versions/{$latest->guid}")
            ->assertOk()->assertJsonPath('data.is_current', true);
    }

    public function test_membership_facts_match_policy_for_each_user_and_keep_history_details_private(): void
    {
        [$owner, $recipient, $document] = $this->versions(2, 'waiting');
        $outsider = User::factory()->create();
        $cold = $document->fresh();
        $warm = $document->fresh('recipientMemberships');
        $this->assertCount(1, $warm->recipientMemberships);
        foreach ([$owner, $recipient, $outsider] as $viewer) {
            foreach (['view', 'manage'] as $ability) {
                $expected = Gate::forUser($viewer)->inspect($ability, $cold);
                [$actual, $queries] = $this->selects(fn () => Gate::forUser($viewer)->inspect($ability, $warm));
                $this->assertSame($expected->allowed(), $actual->allowed());
                $this->assertSame($expected->status(), $actual->status());
                $this->assertCount(0, $queries);
            }
        }
        $event = AuditEvent::factory()->create([
            'document_guid' => $document->guid, 'version_guid' => $document->versions()->firstOrFail()->guid,
            'actor_guid' => $recipient->guid, 'action' => 'assignment.signed',
        ]);
        $this->actingAs($recipient)->getJson("/api/v1/me/history/{$event->guid}")
            ->assertOk()->assertJsonPath('data.can_access_document', true);
        $this->actingAs($outsider)->getJson("/api/v1/me/history/{$event->guid}")->assertNotFound();
        $this->actingAs($owner)->getJson("/api/v1/me/history/{$event->guid}")->assertNotFound();
    }

    public function test_recipient_access_after_real_revision_still_comes_from_the_old_assignment(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $version = $this->publishedVersion($owner, [$recipient]);
        $assignment = $version->assignments()->firstOrFail();
        $this->actingAs($recipient)->postJson("/api/v1/assignments/{$assignment->guid}/revision-request", ['reason' => 'Perbaiki dokumen ini.'])
            ->assertOk();
        $draft = $version->document->versions()->orderByDesc('version_number')->firstOrFail();

        $this->getJson("/api/v1/versions/{$version->guid}")->assertOk()
            ->assertJsonPath('data.is_current', false)->assertJsonPath('data.content_hash', $version->content_hash);
        $this->getJson("/api/v1/versions/{$draft->guid}")->assertNotFound();
        $this->getJson("/api/v1/documents/{$version->document_guid}")->assertOk()
            ->assertJsonPath('data.current_version.is_current', true)
            ->assertJsonMissingPath('data.current_version.content_hash')->assertJsonMissingPath('data.current_version.editor_data');
        $this->getJson('/api/v1/me/history')->assertOk()->assertJsonPath('data.0.can_access_document', true);
        $this->actingAs($owner)->getJson("/api/v1/versions/{$draft->guid}")->assertOk()->assertJsonPath('data.is_current', true);
    }

    /** @return array{User, User, Document} */
    private function versions(int $count, string $status): array
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $document = Document::factory()->create(['owner_guid' => $owner->guid]);
        for ($number = 1; $number <= $count; $number++) {
            $version = DocumentVersion::factory()->create([
                'document_guid' => $document->guid, 'version_number' => $number,
                'content_hash' => str_repeat('a', 64),
            ]);
            SignerAssignment::factory()->create([
                'version_guid' => $version->guid, 'user_guid' => $recipient->guid,
                'recipient_email' => $recipient->email, 'normalized_recipient_email' => $recipient->email,
                'status' => $status,
            ]);
        }

        return [$owner, $recipient, $document];
    }

    /** @return array{mixed, array<int, array<string, mixed>>} */
    private function selects(Closure $callback): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $result = $callback();
            $queries = array_values(array_filter($connection->getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')));
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        return [$result, $queries];
    }
}
