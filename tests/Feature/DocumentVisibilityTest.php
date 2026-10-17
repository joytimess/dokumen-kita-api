<?php

namespace Tests\Feature;

use App\Enums\PreviewStatus;
use App\Enums\VersionStatus;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function hide(Document $document): void
    {
        DB::table('documents')->where('guid', $document->guid)->update(['hidden_at' => now()]);
    }

    private function category(User $owner): DocumentCategory
    {
        return DocumentCategory::create([
            'owner_guid' => $owner->guid,
            'name' => 'Arsip',
            'normalized_name' => 'arsip',
            'color_token' => 'gray',
        ]);
    }

    public function test_visibility_filter_combines_with_search_status_category_and_pagination(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $category = $this->category($owner);
        $first = Document::factory()->create([
            'owner_guid' => $owner->guid,
            'category_guid' => $category->guid,
            'title' => 'Kontrak arsip satu',
            'status' => VersionStatus::InProgress,
        ]);
        $second = Document::factory()->create([
            'owner_guid' => $owner->guid,
            'category_guid' => $category->guid,
            'title' => 'Kontrak arsip dua',
            'status' => VersionStatus::InProgress,
        ]);
        $active = Document::factory()->create([
            'owner_guid' => $owner->guid,
            'category_guid' => $category->guid,
            'title' => 'Kontrak aktif',
            'status' => VersionStatus::InProgress,
        ]);
        $foreign = Document::factory()->create([
            'owner_guid' => $other->guid,
            'category_guid' => null,
            'title' => 'Kontrak asing',
            'status' => VersionStatus::InProgress,
        ]);
        $this->hide($first);
        $this->hide($second);
        $this->hide($foreign);

        $this->actingAs($owner)->getJson('/api/v1/documents')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.guid', $active->guid);

        $query = http_build_query([
            'visibility' => 'hidden',
            'q' => 'Kontrak',
            'status' => 'in_progress',
            'category_guid' => $category->guid,
            'per_page' => 1,
            'page' => 2,
        ]);
        $this->getJson('/api/v1/documents?'.$query)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.guid', $first->guid)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 2);
        $this->getJson('/api/v1/documents?visibility=hidden&status=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/documents?visibility=all')->assertUnprocessable();
    }

    public function test_category_documents_follow_visibility_while_usage_counts_include_hidden(): void
    {
        $owner = User::factory()->create();
        $category = $this->category($owner);
        $hidden = Document::factory()->create(['owner_guid' => $owner->guid, 'category_guid' => $category->guid]);
        $active = Document::factory()->create(['owner_guid' => $owner->guid, 'category_guid' => $category->guid]);
        $this->hide($hidden);

        $this->actingAs($owner)->getJson("/api/v1/categories/{$category->guid}/documents?visibility=hidden")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.guid', $hidden->guid);
        $this->getJson("/api/v1/categories/{$category->guid}/documents?visibility=active")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.guid', $active->guid);
        $this->getJson("/api/v1/categories/{$category->guid}")
            ->assertOk()->assertJsonPath('data.documents_count', 2);
        $this->deleteJson("/api/v1/categories/{$category->guid}")
            ->assertConflict()->assertJsonPath('meta.usage_count', 2);
    }

    public function test_owner_can_hide_and_restore_without_changing_document_or_signer_access(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $document = Document::factory()->create([
            'owner_guid' => $owner->guid,
            'status' => VersionStatus::InProgress,
        ]);
        $version = DocumentVersion::factory()->create([
            'document_guid' => $document->guid,
            'status' => VersionStatus::InProgress,
            'preview_status' => PreviewStatus::Ready,
            'content_hash' => str_repeat('a', 64),
        ]);
        SignerAssignment::factory()->create(['version_guid' => $version->guid, 'user_guid' => $signer->guid]);
        $updatedAt = $document->fresh()->updated_at->toISOString();

        $this->actingAs($signer)->patchJson("/api/v1/documents/{$document->guid}/visibility", ['hidden' => true])->assertForbidden();

        $hidden = $this->actingAs($owner)->patchJson("/api/v1/documents/{$document->guid}/visibility", ['hidden' => true])
            ->assertOk()->assertJsonPath('data.is_hidden', true)->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.current_version.content_hash', str_repeat('a', 64));
        $hiddenAt = $hidden->json('data.hidden_at');
        $this->assertSame($updatedAt, $document->fresh()->updated_at->toISOString());

        $this->patchJson("/api/v1/documents/{$document->guid}/visibility", ['hidden' => true])
            ->assertOk()->assertJsonPath('data.hidden_at', $hiddenAt);
        $this->actingAs($signer)->getJson("/api/v1/documents/{$document->guid}")
            ->assertOk()->assertJsonPath('data.is_hidden', true);
        $this->actingAs($owner);
        $this->patchJson("/api/v1/documents/{$document->guid}/visibility", ['hidden' => false])
            ->assertOk()->assertJsonPath('data.is_hidden', false)->assertJsonPath('data.hidden_at', null)
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertSame(str_repeat('a', 64), $version->fresh()->content_hash);
        $this->assertDatabaseHas('signer_assignments', ['version_guid' => $version->guid]);
    }

    public function test_only_owner_can_change_visibility_and_payload_is_validated(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $document = Document::factory()->create(['owner_guid' => $owner->guid]);

        $this->actingAs($other)->patchJson("/api/v1/documents/{$document->guid}/visibility", ['hidden' => true])->assertNotFound();
        $this->actingAs($owner)->patchJson("/api/v1/documents/{$document->guid}/visibility", ['hidden' => 'yes'])->assertUnprocessable();
        $this->patchJson("/api/v1/documents/{$document->guid}/visibility", [])->assertUnprocessable();
        $this->assertNull($document->fresh()->hidden_at);
    }
}
