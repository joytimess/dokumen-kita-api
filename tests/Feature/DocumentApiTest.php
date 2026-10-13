<?php

namespace Tests\Feature;

use App\Enums\PreviewStatus;
use App\Enums\VersionStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignaturePlaceholder;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DocumentApiTest extends TestCase
{
    use RefreshDatabase;

    private function ownedDraft(User $owner, array $versionAttributes = []): DocumentVersion
    {
        $document = Document::factory()->create(['owner_guid' => $owner->guid]);

        return DocumentVersion::factory()->create(['document_guid' => $document->guid] + $versionAttributes);
    }

    private function pdf(string $name = 'a.pdf', string $extra = ''): File
    {
        return UploadedFileFactory::content($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<$extra>>\n%%EOF");
    }

    private function docx(string $name = 'a.docx'): File
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return UploadedFileFactory::content($name, $bytes);
    }

    public function test_create_draft_and_list_only_own_documents(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Document::factory()->create(['owner_guid' => $other->guid]);

        $response = $this->actingAs($owner)->postJson('/api/v1/documents', ['title' => 'Kontrak A'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Kontrak A')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.current_version.version_number', 1)
            ->assertJsonPath('data.current_version.preview_status', 'none')
            ->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('audit_events', ['action' => 'document.created', 'actor_guid' => $owner->guid]);

        $this->actingAs($owner)->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.guid', $response->json('data.guid'))
            ->assertJsonStructure(['meta' => ['request_id', 'current_page', 'total'], 'links']);
    }

    public function test_list_filters_by_status_and_validates_input(): void
    {
        $owner = User::factory()->create();
        Document::factory()->create(['owner_guid' => $owner->guid, 'status' => VersionStatus::InProgress]);
        Document::factory()->create(['owner_guid' => $owner->guid, 'status' => VersionStatus::Draft]);

        $this->actingAs($owner)->getJson('/api/v1/documents?status=in_progress')->assertJsonCount(1, 'data');
        $this->actingAs($owner)->getJson('/api/v1/documents?status=bogus')->assertUnprocessable();
        $this->actingAs($owner)->getJson('/api/v1/documents?per_page=1000')->assertUnprocessable();
    }

    public function test_guests_cannot_use_document_endpoints(): void
    {
        $this->getJson('/api/v1/documents')->assertUnauthorized();
        $this->postJson('/api/v1/documents', ['title' => 'x'])->assertUnauthorized();
    }

    public function test_outsider_gets_404_even_when_guid_is_known(): void
    {
        $version = $this->ownedDraft(User::factory()->create());
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson("/api/v1/documents/{$version->document_guid}")
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->actingAs($outsider)->patchJson("/api/v1/versions/{$version->guid}", ['title' => 'x'])->assertNotFound();
        $this->actingAs($outsider)->postJson("/api/v1/documents/{$version->document_guid}/imports", ['file' => $this->pdf()])->assertNotFound();
    }

    public function test_recipient_can_view_document_but_not_content_or_edit(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $version = $this->ownedDraft($owner, ['editor_data_json' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'rahasia']]]]]);
        SignerAssignment::factory()->create(['version_guid' => $version->guid, 'user_guid' => $recipient->guid]);

        $this->actingAs($recipient)->getJson("/api/v1/documents/{$version->document_guid}")
            ->assertOk()
            ->assertJsonMissingPath('data.current_version.editor_data');

        $this->actingAs($recipient)->patchJson("/api/v1/versions/{$version->guid}", ['title' => 'x'])->assertForbidden();
        $this->actingAs($owner)->getJson("/api/v1/documents/{$version->document_guid}")
            ->assertJsonPath('data.current_version.editor_data.blocks.0.data.text', 'rahasia');
    }

    public function test_owner_updates_title_and_content_and_stale_flags_follow(): void
    {
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner, ['preview_status' => PreviewStatus::Ready, 'content_hash' => str_repeat('a', 64)]);
        $assignment = SignerAssignment::factory()->create(['version_guid' => $version->guid]);
        $placeholder = SignaturePlaceholder::create(['assignment_guid' => $assignment->guid, 'page_number' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.1]);

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", [
            'title' => 'Judul Baru',
            'editor_data' => ['blocks' => [['type' => 'header', 'data' => ['text' => 'Halo', 'level' => 9]]]],
        ])->assertOk()
            ->assertJsonPath('data.preview_status', 'stale')
            ->assertJsonPath('data.editor_data.blocks.0.data.level', 6);

        $version->refresh();
        $this->assertNull($version->content_hash);
        $this->assertSame('Judul Baru', $version->document->title);
        $this->assertTrue((bool) $placeholder->fresh()->needs_review);
        $this->assertDatabaseHas('audit_events', ['action' => 'version.updated', 'version_guid' => $version->guid]);
    }

    public function test_title_only_change_does_not_invalidate_preview(): void
    {
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner, ['preview_status' => PreviewStatus::Ready]);

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", ['title' => 'Baru'])
            ->assertOk()->assertJsonPath('data.preview_status', 'ready');
    }

    public function test_published_version_cannot_be_edited_or_imported_into(): void
    {
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner, ['status' => VersionStatus::InProgress]);

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", ['title' => 'x'])
            ->assertConflict()->assertJsonPath('error.code', 'VERSION_NOT_EDITABLE');
        $this->actingAs($owner)->postJson("/api/v1/documents/{$version->document_guid}/imports", ['file' => $this->pdf()])
            ->assertConflict()->assertJsonPath('error.code', 'VERSION_NOT_EDITABLE');
    }

    public function test_editor_data_is_sanitized(): void
    {
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner);

        $payload = ['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Hi <script>alert(1)</script><b onclick="x()">tebal</b> <a href="javascript:alert(1)">bad</a> <a href="https://ok.test/x" onmouseover="y()">ok</a> <img src=x onerror=alert(1)>']],
            ['type' => 'list', 'data' => ['style' => 'ordered', 'items' => ['<i>satu</i>', ['content' => 'dua', 'items' => ['tiga']]]]],
            ['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['<u>a</u>', 'b'], ['c', 'd']]]],
        ]];

        $text = $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", ['editor_data' => $payload])
            ->assertOk()
            ->json('data.editor_data.blocks.0.data.text');

        $this->assertStringNotContainsString('script', $text);
        $this->assertStringNotContainsString('onclick', $text);
        $this->assertStringNotContainsString('onmouseover', $text);
        $this->assertStringNotContainsString('javascript:', $text);
        $this->assertStringNotContainsString('<img', $text);
        $this->assertStringContainsString('<b>tebal</b>', $text);
        $this->assertStringContainsString('<a href="https://ok.test/x" rel="noopener noreferrer">ok</a>', $text);
    }

    public function test_unsupported_blocks_and_remote_images_are_rejected(): void
    {
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner);

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", ['editor_data' => ['blocks' => [['type' => 'embed', 'data' => []]]]])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['editor_data.blocks.0.type']]]);

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", ['editor_data' => ['blocks' => [
            ['type' => 'image', 'data' => ['file' => ['url' => 'http://169.254.169.254/latest']]],
        ]]])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['editor_data.blocks.0.data.file.url']]]);

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", ['editor_data' => ['blocks' => [
            ['type' => 'image', 'data' => ['file' => ['url' => 'data:image/png;base64,iVBORw0KGgo='], 'caption' => 'ok']],
        ]]])->assertOk();
    }

    public function test_block_count_limit_is_enforced(): void
    {
        config(['documents.editor.max_blocks' => 2]);
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner);
        $block = ['type' => 'paragraph', 'data' => ['text' => 'x']];

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", ['editor_data' => ['blocks' => [$block, $block, $block]]])
            ->assertUnprocessable();
    }

    public function test_import_pdf_and_docx_are_stored_privately_and_replace_the_previous_source(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner, ['preview_status' => PreviewStatus::Ready]);

        $first = $this->actingAs($owner)->postJson("/api/v1/documents/{$version->document_guid}/imports", ['file' => $this->pdf()])
            ->assertCreated()
            ->assertJsonPath('data.source_type', 'pdf')
            ->assertJsonPath('data.preview_status', 'stale')
            ->assertJsonMissingPath('data.source_path');

        $oldPath = $version->fresh()->source_path;
        Storage::disk('local')->assertExists($oldPath);
        $this->assertStringStartsWith("documents/{$version->document_guid}/sources/", $oldPath);

        $this->actingAs($owner)->postJson("/api/v1/documents/{$version->document_guid}/imports", ['file' => $this->docx()])
            ->assertCreated()->assertJsonPath('data.source_type', 'docx');

        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($version->fresh()->source_path);
        $this->assertDatabaseHas('audit_events', ['action' => 'version.source_imported']);
    }

    public function test_import_rejects_fake_mismatched_oversized_and_encrypted_files(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner);
        $url = "/api/v1/documents/{$version->document_guid}/imports";

        $this->actingAs($owner)->postJson($url, ['file' => UploadedFileFactory::content('evil.pdf', '<?php echo 1;')])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['file']]]);

        $this->actingAs($owner)->postJson($url, ['file' => $this->pdf('renamed.docx')])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['file']]]);

        $this->actingAs($owner)->postJson($url, ['file' => UploadedFileFactory::content('a.exe', 'MZ')])->assertUnprocessable();

        $this->actingAs($owner)->postJson($url, ['file' => $this->pdf('locked.pdf', '/Encrypt 5 0 R')])
            ->assertUnprocessable()->assertJsonPath('error.code', 'PDF_ENCRYPTED');

        config(['documents.import.max_kilobytes' => 1]);
        $this->actingAs($owner)->postJson($url, ['file' => UploadedFileFactory::content('big.pdf', "%PDF-1.4\n".str_repeat('a', 5000))])->assertUnprocessable();

        $this->assertNull($version->fresh()->source_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
