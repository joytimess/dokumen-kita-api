<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_sorting_happens_before_stable_owner_scoped_pagination(): void
    {
        $owner = User::factory()->create();
        $categories = collect(['Zulu', 'alpha', 'Beta'])->map(fn ($name) => DocumentCategory::create([
            'owner_guid' => $owner->guid, 'name' => $name, 'normalized_name' => mb_strtolower($name), 'color_token' => 'gray',
        ]));
        // Equal timestamps exercise the ID tie breaker on every page.
        DocumentCategory::where('owner_guid', $owner->guid)->update(['created_at' => '2026-01-01 00:00:00']);
        Document::factory()->create(['owner_guid' => $owner->guid, 'category_guid' => $categories[0]->guid]);
        Document::factory()->create(['owner_guid' => $owner->guid, 'category_guid' => $categories[2]->guid]);
        Document::factory()->create(['owner_guid' => $owner->guid, 'category_guid' => $categories[2]->guid, 'hidden_at' => now()]);
        DocumentCategory::create([
            'owner_guid' => User::factory()->create()->guid, 'name' => 'Private', 'normalized_name' => 'private', 'color_token' => 'red',
        ]);

        $this->actingAs($owner);
        foreach ([
            ['created_at', 'desc', [2, 1, 0]], ['created_at', 'asc', [0, 1, 2]],
            ['name', 'asc', [1, 2, 0]], ['name', 'desc', [0, 2, 1]],
            ['documents_count', 'desc', [2, 0, 1]], ['documents_count', 'asc', [1, 0, 2]],
        ] as [$sort, $direction, $order]) {
            foreach ($order as $index => $categoryIndex) {
                $this->getJson('/api/v1/categories?'.http_build_query([
                    'per_page' => 1, 'page' => $index + 1, 'sort_by' => $sort, 'sort_direction' => $direction,
                ]))->assertOk()->assertJsonCount(1, 'data')
                    ->assertJsonPath('data.0.guid', $categories[$categoryIndex]->guid)
                    ->assertJsonPath('data.0.documents_count', [1, 0, 2][$categoryIndex])
                    ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3)
                    ->assertJsonStructure(['links' => ['next', 'prev']]);
            }
        }
        $this->getJson('/api/v1/categories?per_page=1')->assertOk()->assertJsonPath('data.0.guid', $categories[2]->guid);
        $this->getJson('/api/v1/categories?per_page=1&sort_by=documents_count&sort_direction=desc&page=2')
            ->assertOk()->assertJsonPath('data.0.guid', $categories[0]->guid);

        $categories[0]->forceFill(['created_at' => '2027-01-01 00:00:00'])->save();
        $this->getJson('/api/v1/categories?per_page=1')->assertOk()->assertJsonPath('data.0.guid', $categories[0]->guid);
        $this->getJson('/api/v1/categories?per_page=1&sort_direction=asc')->assertOk()->assertJsonPath('data.0.guid', $categories[1]->guid);

        $empty = DocumentCategory::create([
            'owner_guid' => $owner->guid, 'name' => 'Empty', 'normalized_name' => 'empty', 'color_token' => 'gray',
        ]);
        foreach (['asc' => [$categories[1], $empty], 'desc' => [$empty, $categories[1]]] as $direction => $tied) {
            foreach ($tied as $index => $category) {
                $page = $direction === 'asc' ? $index + 1 : $index + 3;
                $this->getJson("/api/v1/categories?per_page=1&sort_by=documents_count&sort_direction={$direction}&page={$page}")
                    ->assertOk()->assertJsonPath('data.0.guid', $category->guid);
            }
        }
    }

    public function test_category_search_is_case_insensitive_literal_and_applied_before_pagination(): void
    {
        $owner = User::factory()->create();
        foreach (['Annual 50%_!', 'Annual 500', 'Another', 'annual 50%_! archive'] as $name) {
            DocumentCategory::create([
                'owner_guid' => $owner->guid, 'name' => $name, 'normalized_name' => mb_strtolower($name), 'color_token' => 'gray',
            ]);
        }
        DocumentCategory::create([
            'owner_guid' => User::factory()->create()->guid, 'name' => 'Annual 50%_! private',
            'normalized_name' => 'annual 50%_! private', 'color_token' => 'gray',
        ]);
        $this->actingAs($owner)->getJson('/api/v1/categories?'.http_build_query([
            'q' => '  ANNUAL 50%_!  ', 'per_page' => 1, 'page' => 2, 'sort_by' => 'name', 'sort_direction' => 'asc',
        ]))->assertOk()->assertJsonPath('data.0.name', 'annual 50%_! archive')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/categories?per_page=10&q=missing')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_category_list_without_parameters_keeps_all_alphabetical_options(): void
    {
        $owner = User::factory()->create();
        foreach (range(12, 1) as $index) {
            $name = sprintf('Category %02d', $index);
            DocumentCategory::create([
                'owner_guid' => $owner->guid, 'name' => $name, 'normalized_name' => mb_strtolower($name), 'color_token' => 'gray',
            ]);
        }
        $this->actingAs($owner)->getJson('/api/v1/categories')->assertOk()->assertJsonCount(12, 'data')
            ->assertJsonPath('data.0.name', 'Category 01')->assertJsonPath('data.11.name', 'Category 12')
            ->assertJsonMissingPath('meta.current_page')->assertJsonMissingPath('links');
    }

    public function test_category_list_validates_query_parameters(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([
            ['sort_by', 'owner_guid'], ['sort_direction', 'sideways'], ['page', 0], ['page', 'abc'],
            ['per_page', 0], ['per_page', 101], ['q', str_repeat('a', 256)], ['q', ['array']],
        ] as [$field, $value]) {
            $this->getJson('/api/v1/categories?'.http_build_query([$field => $value]))
                ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
    }

    public function test_title_search_filters_before_pagination_and_only_returns_owned_documents(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $match = Document::factory()->create(['owner_guid' => $owner->guid, 'title' => 'Quarter + (draft)']);
        Document::factory()->count(17)->create(['owner_guid' => $owner->guid]);
        Document::factory()->create(['owner_guid' => $other->guid, 'title' => 'Quarter + (private)']);

        $this->actingAs($owner)->getJson('/api/v1/documents?q='.urlencode('+ (draft)').'&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.guid', $match->guid)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_title_search_treats_sql_wildcards_as_literal_text(): void
    {
        $owner = User::factory()->create();
        $match = Document::factory()->create(['owner_guid' => $owner->guid, 'title' => 'Laporan 50% selesai']);
        Document::factory()->create(['owner_guid' => $owner->guid, 'title' => 'Laporan 500 selesai']);

        $this->actingAs($owner)->getJson('/api/v1/documents?q=%25')
            ->assertOk()
            ->assertJsonPath('data.0.guid', $match->guid)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_category_names_are_trimmed_unique_per_owner_and_colors_use_the_fixed_palette(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $payload = ['name' => '  Laporan  ', 'color_token' => 'blue', 'description' => '  Arsip  '];

        $created = $this->actingAs($owner)->postJson('/api/v1/categories', $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Laporan')
            ->assertJsonPath('data.description', 'Arsip');

        $this->postJson('/api/v1/categories', ['name' => 'laporan', 'color_token' => 'gray'])->assertUnprocessable();
        $this->postJson('/api/v1/categories', ['name' => 'Salah', 'color_token' => '#ff0000'])->assertUnprocessable();
        $this->actingAs($other)->postJson('/api/v1/categories', ['name' => 'Laporan', 'color_token' => 'gray'])->assertCreated();
        $this->actingAs($owner)->getJson('/api/v1/categories/'.$created->json('data.guid'))->assertOk()->assertJsonPath('data.documents_count', 0);
        $second = $this->postJson('/api/v1/categories', ['name' => 'Lainnya', 'color_token' => 'gray'])->assertCreated();
        $this->putJson('/api/v1/categories/'.$second->json('data.guid'), ['name' => ' LAPORAN ', 'color_token' => 'blue'])->assertUnprocessable();

        $this->assertDatabaseHas('document_categories', [
            'guid' => $created->json('data.guid'), 'owner_guid' => $owner->guid,
            'name' => 'Laporan', 'normalized_name' => 'laporan', 'description' => 'Arsip',
        ]);
    }

    public function test_single_and_bulk_category_assignment_filter_documents_and_preserve_document_data(): void
    {
        $owner = User::factory()->create();
        $category = DocumentCategory::create([
            'owner_guid' => $owner->guid, 'name' => 'Selesai', 'normalized_name' => 'selesai', 'color_token' => 'green',
        ]);
        $first = Document::factory()->create(['owner_guid' => $owner->guid, 'title' => 'Berkas Satu']);
        $second = Document::factory()->create(['owner_guid' => $owner->guid, 'title' => 'Berkas Dua']);

        $this->actingAs($owner)->patchJson("/api/v1/documents/{$first->guid}/category", ['category_guid' => $category->guid])
            ->assertOk()->assertJsonPath('data.category_guid', $category->guid);
        $this->patchJson('/api/v1/documents/categories', [
            'document_guids' => [$second->guid], 'category_guid' => $category->guid,
        ])->assertOk()->assertJsonPath('data.updated_count', 1);

        $this->getJson('/api/v1/documents?category_guid='.$category->guid)
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/documents?uncategorized=true')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/documents?category_guid='.$category->guid.'&uncategorized=true')->assertUnprocessable();

        $this->assertDatabaseHas('documents', ['guid' => $first->guid, 'title' => 'Berkas Satu', 'category_guid' => $category->guid]);
        $this->assertSame(2, DocumentCategory::withCount('documents')->findOrFail($category->id)->documents_count);
    }

    public function test_bulk_assignment_rejects_a_foreign_document_without_partial_changes(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $category = DocumentCategory::create([
            'owner_guid' => $owner->guid, 'name' => 'Internal', 'normalized_name' => 'internal', 'color_token' => 'gray',
        ]);
        $owned = Document::factory()->create(['owner_guid' => $owner->guid]);
        $foreign = Document::factory()->create(['owner_guid' => $other->guid]);

        $this->actingAs($owner)->patchJson('/api/v1/documents/categories', [
            'document_guids' => [$owned->guid, $foreign->guid], 'category_guid' => $category->guid,
        ])->assertNotFound();

        $this->assertNull($owned->fresh()->category_guid);
        $this->actingAs($other)->patchJson("/api/v1/documents/{$owned->guid}/category", ['category_guid' => $category->guid])->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/documents?category_guid='.$category->guid)->assertNotFound();
    }

    public function test_used_category_cannot_be_deleted_and_empty_category_deletion_keeps_documents(): void
    {
        $owner = User::factory()->create();
        $category = DocumentCategory::create([
            'owner_guid' => $owner->guid, 'name' => 'Work', 'normalized_name' => 'work', 'color_token' => 'yellow',
        ]);
        $document = Document::factory()->create(['owner_guid' => $owner->guid, 'category_guid' => $category->guid]);

        $this->actingAs($owner)->deleteJson("/api/v1/categories/{$category->guid}")
            ->assertConflict()->assertJsonPath('error.code', 'CATEGORY_IN_USE')->assertJsonPath('meta.usage_count', 1);

        $this->patchJson("/api/v1/documents/{$document->guid}/category", ['category_guid' => null])->assertOk();
        $this->deleteJson("/api/v1/categories/{$category->guid}")->assertNoContent();
        $this->assertDatabaseHas('documents', ['guid' => $document->guid]);
        $this->assertDatabaseMissing('document_categories', ['guid' => $category->guid]);
    }
}
