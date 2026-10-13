<?php

namespace Tests\Feature;

use App\Enums\PreviewStatus;
use App\Enums\VersionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SigningSetupApiTest extends TestCase
{
    use RefreshDatabase, SigningFixtures;

    public function test_owner_can_be_a_signer_and_emails_are_normalized(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $version = $this->draft($owner);

        $this->actingAs($owner)->putJson("/api/v1/versions/{$version->guid}/signers", ['signers' => [
            ['email' => ' First+tag@Example.test ', 'sequence_number' => 1],
            ['email' => ' OWNER@example.test ', 'sequence_number' => 2],
        ]])->assertOk()->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.assignments.0.recipient_email', 'first+tag@example.test')
            ->assertJsonPath('data.assignments.1.recipient_email', 'owner@example.test')
            ->assertJsonMissingPath('data.assignments.0.id');

        $this->assertDatabaseHas('signer_assignments', ['version_guid' => $version->guid, 'normalized_recipient_email' => 'first+tag@example.test', 'sequence_number' => 1, 'user_guid' => null]);
        $this->assertDatabaseHas('audit_events', ['action' => 'version.signers_updated', 'actor_guid' => $owner->guid]);
    }

    public function test_reordering_retains_assignment_guid_and_placeholders(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $first = $version->assignments()->where('sequence_number', 1)->firstOrFail();
        $second = $version->assignments()->where('sequence_number', 2)->firstOrFail();
        $placeholder = $first->placeholders()->firstOrFail();

        $this->actingAs($owner)->putJson("/api/v1/versions/{$version->guid}/signers", ['signers' => [
            ['email' => $second->recipient_email, 'sequence_number' => 1],
            ['email' => $first->recipient_email, 'sequence_number' => 2],
        ]])->assertOk();

        $this->assertDatabaseHas('signer_assignments', ['guid' => $first->guid, 'sequence_number' => 2]);
        $this->assertDatabaseHas('signer_assignments', ['guid' => $second->guid, 'sequence_number' => 1]);
        $this->assertModelExists($placeholder);
        $this->assertSame(PreviewStatus::Ready, $version->fresh()->preview_status);
    }

    public function test_replacing_and_clearing_signers_removes_only_their_draft_placeholders(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $first = $version->assignments()->where('sequence_number', 1)->firstOrFail();
        $second = $version->assignments()->where('sequence_number', 2)->firstOrFail();

        $this->actingAs($owner)->putJson("/api/v1/versions/{$version->guid}/signers", ['signers' => [
            ['email' => $second->recipient_email, 'sequence_number' => 1],
            ['email' => 'new@example.test', 'sequence_number' => 2],
        ]])->assertOk();

        $this->assertModelMissing($first);
        $this->assertDatabaseMissing('signature_placeholders', ['assignment_guid' => $first->guid]);
        $this->assertDatabaseHas('signature_placeholders', ['assignment_guid' => $second->guid]);

        $this->putJson("/api/v1/versions/{$version->guid}/signers", ['signers' => []])->assertOk()->assertJsonCount(0, 'data.assignments');
        $this->assertDatabaseCount('signer_assignments', 0);
        $this->assertDatabaseCount('signature_placeholders', 0);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidSigners(): array
    {
        return [
            'missing' => [[]],
            'duplicate normalized email' => [['signers' => [['email' => 'A@example.test', 'sequence_number' => 1], ['email' => ' a@example.test ', 'sequence_number' => 2]]]],
            'duplicate sequence' => [['signers' => [['email' => 'a@example.test', 'sequence_number' => 1], ['email' => 'b@example.test', 'sequence_number' => 1]]]],
            'gap' => [['signers' => [['email' => 'a@example.test', 'sequence_number' => 2]]]],
            'zero' => [['signers' => [['email' => 'a@example.test', 'sequence_number' => 0]]]],
            'invalid email' => [['signers' => [['email' => 'bad', 'sequence_number' => 1]]]],
            'unexpected privilege' => [['signers' => [['email' => 'a@example.test', 'sequence_number' => 1, 'user_guid' => 'attacker']]]],
            'not a list' => [['signers' => ['a' => ['email' => 'a@example.test', 'sequence_number' => 1]]]],
        ];
    }

    #[DataProvider('invalidSigners')]
    public function test_invalid_signers_return_422_without_writes(array $payload): void
    {
        $owner = User::factory()->create();
        $version = $this->draft($owner);

        $this->actingAs($owner)->putJson("/api/v1/versions/{$version->guid}/signers", $payload)
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseCount('signer_assignments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_signer_limit_returns_422(): void
    {
        config(['documents.signing.max_signers' => 1]);
        $owner = User::factory()->create();
        $version = $this->draft($owner);

        $this->actingAs($owner)->putJson("/api/v1/versions/{$version->guid}/signers", ['signers' => [
            ['email' => 'a@example.test', 'sequence_number' => 1],
            ['email' => 'b@example.test', 'sequence_number' => 2],
        ]])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['signers']]]);

        $this->assertDatabaseCount('signer_assignments', 0);
    }

    public function test_placeholders_are_replaced_and_review_is_recorded_against_current_preview(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $assignment = $version->assignments()->firstOrFail();
        $old = $assignment->placeholders()->firstOrFail();
        $old->update(['needs_review' => true]);

        $this->actingAs($owner)->putJson("/api/v1/assignments/{$assignment->guid}/placeholders", ['placeholders' => [$this->position()]])
            ->assertOk()->assertJsonCount(1, 'data.placeholders')
            ->assertJsonPath('data.placeholders.0.page_number', 2)
            ->assertJsonPath('data.placeholders.0.needs_review', false);

        $this->assertModelMissing($old);
        $this->assertDatabaseHas('signature_placeholders', ['assignment_guid' => $assignment->guid, 'page_number' => 2, 'needs_review' => false]);
        $this->assertDatabaseHas('audit_events', ['action' => 'assignment.placeholders_updated', 'version_guid' => $version->guid]);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidPositions(): array
    {
        return [
            'negative coordinate' => [['x' => -0.1]],
            'zero width' => [['width' => 0]],
            'negative height' => [['height' => -1]],
            'right overflow' => [['x' => 0.9]],
            'bottom overflow' => [['y' => 0.9]],
            'missing page' => [['page_number' => 3]],
            'zero page' => [['page_number' => 0]],
            'fractional page' => [['page_number' => 1.5]],
            'too much precision' => [['width' => 0.0000001]],
            'unexpected reviewed flag' => [['needs_review' => false]],
        ];
    }

    #[DataProvider('invalidPositions')]
    public function test_invalid_position_returns_422_and_keeps_existing_placeholders(array $changes): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $assignment = $version->assignments()->firstOrFail();
        $old = $assignment->placeholders()->firstOrFail();

        $this->actingAs($owner)->putJson("/api/v1/assignments/{$assignment->guid}/placeholders", ['placeholders' => [array_replace($this->position(), $changes)]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertModelExists($old);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_stale_preview_returns_409_for_placement_but_allows_clearing(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $assignment = $version->assignments()->firstOrFail();
        $version->update(['preview_status' => PreviewStatus::Stale]);

        $this->actingAs($owner)->putJson("/api/v1/assignments/{$assignment->guid}/placeholders", ['placeholders' => [$this->position()]])
            ->assertConflict()->assertJsonPath('error.code', 'PREVIEW_NOT_READY');
        $this->assertSame(1, $assignment->placeholders()->count());

        $this->putJson("/api/v1/assignments/{$assignment->guid}/placeholders", ['placeholders' => []])->assertOk();
        $this->assertSame(0, $assignment->placeholders()->count());
    }

    public function test_outsider_gets_404_and_assigned_recipient_gets_403_for_setup(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $assignment = $version->assignments()->firstOrFail();
        $assignment->update(['user_guid' => $recipient->guid]);

        $this->actingAs(User::factory()->create())->putJson("/api/v1/versions/{$version->guid}/signers", ['signers' => []])->assertNotFound();
        $this->putJson("/api/v1/assignments/{$assignment->guid}/placeholders", ['placeholders' => []])->assertNotFound();
        $this->actingAs($recipient)->putJson("/api/v1/versions/{$version->guid}/signers", ['signers' => []])->assertForbidden();
        $this->putJson("/api/v1/assignments/{$assignment->guid}/placeholders", ['placeholders' => []])->assertForbidden();

        $this->assertSame(2, $version->assignments()->count());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_published_setup_is_immutable_and_returns_409(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $version->update(['status' => VersionStatus::InProgress]);
        $assignment = $version->assignments()->firstOrFail();

        $this->actingAs($owner)->putJson("/api/v1/versions/{$version->guid}/signers", ['signers' => []])
            ->assertConflict()->assertJsonPath('error.code', 'VERSION_NOT_EDITABLE');
        $this->putJson("/api/v1/assignments/{$assignment->guid}/placeholders", ['placeholders' => []])
            ->assertConflict()->assertJsonPath('error.code', 'VERSION_NOT_EDITABLE');

        $this->assertDatabaseCount('signer_assignments', 2);
        $this->assertDatabaseCount('signature_placeholders', 2);
    }

    public function test_placeholder_limit_returns_422_without_replacing_positions(): void
    {
        config(['documents.signing.max_placeholders_per_signer' => 1]);
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $assignment = $version->assignments()->firstOrFail();
        $old = $assignment->placeholders()->firstOrFail();

        $this->actingAs($owner)->putJson("/api/v1/assignments/{$assignment->guid}/placeholders", ['placeholders' => [$this->position(), $this->position()]])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['placeholders']]]);

        $this->assertModelExists($old);
    }

    public function test_content_changes_require_new_preview_and_explicit_placeholder_review_before_publish(): void
    {
        $owner = User::factory()->create();
        $version = $this->preparedDraft($owner, [$owner->email]);
        $assignment = $version->assignments()->firstOrFail();

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", [
            'editor_data' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Konten baru']]]],
        ])->assertOk()->assertJsonPath('data.preview_status', 'stale');
        $this->assertNull($version->fresh()->preview_page_count);
        $this->assertNull($version->fresh()->content_hash);
        $this->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertConflict()->assertJsonPath('error.code', 'PREVIEW_NOT_READY');

        $this->storePreview($version);
        $this->postJson("/api/v1/versions/{$version->guid}/publish")
            ->assertConflict()->assertJsonPath('error.code', 'PLACEHOLDERS_NEED_REVIEW');
        $this->putJson("/api/v1/assignments/{$assignment->guid}/placeholders", ['placeholders' => [$this->position()]])->assertOk();
        $this->postJson("/api/v1/versions/{$version->guid}/publish")->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseCount('email_notifications', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'assignment.placeholders_updated']);
    }
}
