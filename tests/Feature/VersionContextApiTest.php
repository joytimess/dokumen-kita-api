<?php

namespace Tests\Feature;

use App\Enums\VersionStatus;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\PublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersionContextApiTest extends TestCase
{
    use RefreshDatabase, SigningFixtures;

    public function test_ready_claim_supplies_navigation_context_without_exposing_editor(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create(['email' => 'first@example.test']);
        $version = $this->preparedDraft($owner);
        $this->app->make(PublishService::class)->publish($owner, $version);
        $assignment = $version->assignments()->where('sequence_number', 1)->firstOrFail();

        $this->actingAs($recipient)->postJson('/api/v1/invitations/claim', ['token' => $this->invitationToken($assignment)])
            ->assertOk()->assertJsonPath('data.document_guid', $version->document_guid)
            ->assertJsonPath('data.document_title', $version->document->title)
            ->assertJsonPath('data.version_number', 1)->assertJsonCount(1, 'data.placeholders');
        $this->getJson('/api/v1/versions/'.$version->guid)->assertOk()
            ->assertJsonPath('data.document_guid', $version->document_guid)->assertJsonPath('data.is_current', true)
            ->assertJsonMissingPath('data.editor_data')->assertJsonPath('data.content_hash', $version->content_hash)
            ->assertJsonCount(1, 'data.assignments');
    }

    public function test_historical_metadata_is_exact_and_authorized(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $version->update(['status' => VersionStatus::Superseded]);
        DocumentVersion::factory()->create(['document_guid' => $version->document_guid, 'version_number' => 2]);

        $this->getJson('/api/v1/versions/'.$version->guid)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/versions/'.$version->guid)->assertNotFound();
        $this->actingAs($owner)->getJson('/api/v1/versions/'.$version->guid)->assertOk()
            ->assertJsonPath('data.guid', $version->guid)->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.status', 'superseded')->assertJsonPath('data.is_current', false);
    }
}
