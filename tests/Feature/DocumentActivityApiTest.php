<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentActivityApiTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    public function test_owner_can_page_audit_versions_and_email_status(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->publishedVersion($owner, [$signer]);
        $document = $version->document;

        $this->actingAs($owner)->getJson("/api/v1/documents/{$document->guid}/audit?per_page=1")
            ->assertOk()->assertJsonPath('data.0.action', 'version.published')->assertJsonPath('meta.per_page', 1);
        $this->actingAs($owner)->getJson("/api/v1/documents/{$document->guid}/versions")
            ->assertOk()->assertJsonPath('data.0.guid', $version->guid);
        $this->actingAs($owner)->getJson("/api/v1/documents/{$document->guid}/emails")
            ->assertOk()->assertJsonPath('data.0.status', 'queued')
            ->assertJsonPath('data.0.notification_type', 'invitation_ready');
    }

    public function test_recipient_can_only_read_assigned_document_activity_and_cannot_read_email_outbox(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $version = $this->publishedVersion($owner, [$signer]);
        $document = $version->document;

        $this->actingAs($signer)->getJson("/api/v1/documents/{$document->guid}/audit")
            ->assertOk()->assertJsonPath('data.0.action', 'version.published');
        $this->actingAs($signer)->getJson("/api/v1/documents/{$document->guid}/versions")
            ->assertOk()->assertJsonPath('data.0.guid', $version->guid);
        $this->actingAs($signer)->getJson("/api/v1/documents/{$document->guid}/emails")->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/documents/{$document->guid}/audit")->assertNotFound();
        $this->actingAs($owner)->getJson("/api/v1/documents/{$document->guid}/audit?per_page=101")->assertUnprocessable();
    }
}
