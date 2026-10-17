<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SigningHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_history_filters_by_actor_title_and_jakarta_date_and_keeps_stable_pagination(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $matching = $this->event($owner, $actor, '50% Contract', 'assignment.signed', '2026-10-16 17:00:00');
        $this->event($owner, $actor, '50% Contract', 'version.revision_requested', '2026-10-17 16:59:00', ['reason' => 'Perbaiki!']);
        $this->event($owner, $actor, '50% Contract', 'assignment.signed', '2026-10-17 17:00:00');
        $this->event($owner, $other, '50% Contract', 'assignment.signed', '2026-10-17 12:00:00');
        $this->event($owner, $actor, 'Other Contract', 'assignment.signed', '2026-10-17 12:00:00');

        $this->actingAs($actor)->getJson('/api/v1/me/history?q=%25&date_from=2026-10-17&date_to=2026-10-17&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'version.revision_requested')
            ->assertJsonPath('meta.total', 2);

        $detail = $this->getJson('/api/v1/me/history/'.$matching->guid)
            ->assertOk()
            ->assertJsonPath('data.actor_snapshot_available', true)
            ->assertJsonPath('data.actor_snapshot.name', $actor->name)
            ->assertJsonPath('data.can_access_document', false)
            ->assertJsonPath('data.document.guid', null)
            ->assertJsonPath('data.version_guid', null);

        $this->assertArrayNotHasKey('revision_reason', $detail->json('data'));
        $this->getJson('/api/v1/documents/'.$matching->document_guid)->assertNotFound();
    }

    public function test_revision_detail_returns_reason_and_legacy_events_report_missing_snapshots(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()->create();
        $oldName = $actor->name;
        $oldEmail = $actor->email;
        $revision = $this->event($owner, $actor, 'Draft', 'version.revision_requested', now()->toDateTimeString(), ['reason' => 'Tambahkan lampiran.']);
        $legacy = $this->event($owner, $actor, 'Legacy', 'assignment.signed', now()->toDateTimeString(), includeSnapshot: false);
        $actor->update(['name' => 'Nama Baru', 'email' => 'new-actor@example.test']);
        SignerAssignment::factory()->create(['version_guid' => $revision->version_guid, 'user_guid' => $actor->guid]);

        $this->actingAs($actor)->getJson('/api/v1/me/history/'.$revision->guid)
            ->assertOk()
            ->assertJsonPath('data.revision_reason', 'Tambahkan lampiran.')
            ->assertJsonPath('data.actor_snapshot.name', $oldName)
            ->assertJsonPath('data.actor_snapshot.email', $oldEmail)
            ->assertJsonPath('data.can_access_document', true)
            ->assertJsonPath('data.document.guid', $revision->document_guid);

        $this->getJson('/api/v1/me/history/'.$legacy->guid)
            ->assertOk()
            ->assertJsonPath('data.actor_snapshot_available', false)
            ->assertJsonPath('data.actor_snapshot.name', null);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/me/history/'.$revision->guid)->assertNotFound();
    }

    /** @param array<string, mixed> $metadata */
    private function event(User $owner, User $actor, string $title, string $action, string $createdAt, array $metadata = [], bool $includeSnapshot = true): AuditEvent
    {
        $document = Document::factory()->create(['owner_guid' => $owner->guid, 'title' => $title]);
        $version = DocumentVersion::factory()->create(['document_guid' => $document->guid]);
        if ($includeSnapshot) {
            $metadata['actor_snapshot'] = ['name' => $actor->name, 'email' => $actor->email];
        }

        $event = AuditEvent::create([
            'document_guid' => $document->guid,
            'version_guid' => $version->guid,
            'actor_guid' => $actor->guid,
            'action' => $action,
            'metadata_json' => $metadata ?: null,
        ]);
        $event->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $event;
    }
}
