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
            'editor_revision' => 1,
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

        $cellParagraph = [
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Sel', 'marks' => [['type' => 'fontFamily', 'attrs' => ['fontFamily' => 'Georgia']]]]],
        ];
        $fontCell = ['type' => 'table', 'data' => ['content' => [[[$cellParagraph]]]]];
        $payload = ['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Hi <span data-font-family="Arial">surat</span><script>alert(1)</script><b onclick="x()">tebal</b> <a href="javascript:alert(1)">bad</a> <a href="https://ok.test/x" onmouseover="y()">ok</a> <img src=x onerror=alert(1)>']],
            ['type' => 'list', 'data' => ['style' => 'ordered', 'items' => ['<i>satu</i>', ['content' => 'dua', 'items' => ['tiga']]]]],
            ['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['<u>a</u>', 'b'], ['c', 'd']]]],
            $fontCell,
        ]];

        $text = $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", ['editor_revision' => 1, 'editor_data' => $payload])
            ->assertOk()
            ->assertJsonPath('data.editor_data.blocks.3.data.content.0.0.0.content.0.marks.0.attrs.fontFamily', 'Georgia')
            ->json('data.editor_data.blocks.0.data.text');

        $this->assertStringNotContainsString('script', $text);
        $this->assertStringNotContainsString('onclick', $text);
        $this->assertStringNotContainsString('onmouseover', $text);
        $this->assertStringNotContainsString('javascript:', $text);
        $this->assertStringNotContainsString('<img', $text);
        $this->assertStringContainsString('<span data-font-family="Arial">surat</span>', $text);
        $this->assertStringContainsString('<b>tebal</b>', $text);
        $this->assertStringContainsString('<a href="https://ok.test/x" rel="noopener noreferrer">ok</a>', $text);

        $this->patchJson("/api/v1/versions/{$version->guid}", [
            'editor_revision' => 2,
            'editor_data' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => '<span data-font-family="Papyrus">teks</span>']]]],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_delimiter_is_preserved_and_moving_it_invalidates_the_preview(): void
    {
        $owner = User::factory()->create();
        $paragraphOne = ['type' => 'paragraph', 'data' => ['text' => 'Bagian pertama']];
        $paragraphTwo = ['type' => 'paragraph', 'data' => ['text' => 'Bagian kedua']];
        $delimiter = ['type' => 'delimiter', 'data' => (object) []];

        $created = $this->actingAs($owner)->postJson('/api/v1/documents', [
            'title' => 'Dokumen dengan pemisah',
            'editor_data' => ['blocks' => [$paragraphOne, $delimiter, $paragraphTwo]],
        ])->assertCreated()
            ->assertJsonPath('data.current_version.editor_data.blocks.1.type', 'delimiter')
            ->assertJsonPath('data.current_version.editor_data.blocks.1.data', []);

        $versionGuid = $created->json('data.current_version.guid');
        $version = DocumentVersion::where('guid', $versionGuid)->firstOrFail();
        $version->update(['preview_status' => PreviewStatus::Ready, 'content_hash' => str_repeat('a', 64)]);
        $assignment = SignerAssignment::factory()->create(['version_guid' => $versionGuid]);
        $placeholder = SignaturePlaceholder::factory()->create([
            'assignment_guid' => $assignment->guid,
            'needs_review' => false,
        ]);
        $movedBlocks = [$paragraphOne, $paragraphTwo, $delimiter];

        $this->patchJson("/api/v1/versions/{$versionGuid}", [
            'editor_revision' => 1,
            'editor_data' => ['blocks' => $movedBlocks],
        ])->assertOk()
            ->assertJsonPath('data.editor_data.blocks.1.type', 'paragraph')
            ->assertJsonPath('data.editor_data.blocks.2.type', 'delimiter')
            ->assertJsonPath('data.editor_data.blocks.2.data', [])
            ->assertJsonPath('data.editor_revision', 2)
            ->assertJsonPath('data.preview_status', 'stale')
            ->assertJsonPath('data.content_hash', null);

        $this->getJson("/api/v1/versions/{$versionGuid}")->assertOk()
            ->assertJsonPath('data.editor_data.blocks.2.type', 'delimiter')
            ->assertJsonPath('data.editor_data.blocks.2.data', []);
        $this->assertSame([$paragraphOne, $paragraphTwo, ['type' => 'delimiter', 'data' => []]], $version->fresh()->editor_data_json['blocks']);
        $this->assertTrue($placeholder->fresh()->needs_review);

        $invalidBlocks = [$paragraphOne, $paragraphTwo, ['type' => 'delimiter', 'data' => ['style' => 'dashed']]];
        $this->patchJson("/api/v1/versions/{$versionGuid}", [
            'editor_revision' => 2,
            'editor_data' => ['blocks' => $invalidBlocks],
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['editor_data.blocks.2.data.style']]]);
        $this->assertSame(2, $version->fresh()->editor_revision);
    }

    public function test_table_border_choices_are_saved_per_table_and_default_to_visible(): void
    {
        $owner = User::factory()->create();
        $tables = ['blocks' => [
            ['type' => 'table', 'data' => ['withHeadings' => true, 'showBorders' => false, 'content' => [['Nama'], ['A']]]],
            ['type' => 'table', 'data' => ['withHeadings' => false, 'content' => [['Nilai'], ['B']]]],
        ]];

        $created = $this->actingAs($owner)->postJson('/api/v1/documents', ['title' => 'Pilihan garis', 'editor_data' => $tables])
            ->assertCreated()
            ->assertJsonPath('data.current_version.editor_data.blocks.0.data.showBorders', false)
            ->assertJsonPath('data.current_version.editor_data.blocks.1.data.showBorders', true);
        $versionGuid = $created->json('data.current_version.guid');

        $this->getJson("/api/v1/versions/{$versionGuid}")
            ->assertOk()
            ->assertJsonPath('data.editor_data.blocks.0.data.showBorders', false)
            ->assertJsonPath('data.editor_data.blocks.1.data.showBorders', true);
        $this->assertSame($tables['blocks'][0]['data']['showBorders'], DocumentVersion::where('guid', $versionGuid)->firstOrFail()->editor_data_json['blocks'][0]['data']['showBorders']);
    }

    public function test_merged_table_cells_and_supported_styles_round_trip_through_the_api(): void
    {
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner);
        $paragraph = fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        $table = ['blocks' => [['type' => 'table', 'data' => [
            'withHeadings' => true,
            'rowMinHeights' => [null, 44],
            'content' => [
                [
                    ['attrs' => ['colspan' => 2, 'colwidth' => [120, 90], 'align' => 'center', 'verticalAlign' => 'middle', 'backgroundColor' => 'blue', 'border' => false], 'content' => [$paragraph('Gabungan')]],
                    ['attrs' => ['rowspan' => 2, 'colwidth' => [72]], 'content' => [$paragraph('Vertikal')]],
                    ['attrs' => ['colwidth' => [80]], 'content' => [$paragraph('Kolom')]],
                ],
                [
                    ['attrs' => ['colwidth' => [120]], 'content' => [$paragraph('Baris kedua')]],
                    ['attrs' => ['colwidth' => [90]], 'content' => [$paragraph('Isi')]],
                    ['attrs' => ['colwidth' => [80]], 'content' => [$paragraph('Ujung')]],
                ],
            ],
        ]]]];

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", [
            'editor_revision' => 1, 'editor_data' => $table,
        ])->assertOk()
            ->assertJsonPath('data.editor_data.blocks.0.data.content.0.0.attrs.colspan', 2)
            ->assertJsonPath('data.editor_data.blocks.0.data.content.0.0.attrs.backgroundColor', 'blue')
            ->assertJsonPath('data.editor_data.blocks.0.data.content.0.1.attrs.rowspan', 2)
            ->assertJsonPath('data.editor_data.blocks.0.data.rowMinHeights.1', 44);

        $this->getJson("/api/v1/versions/{$version->guid}")->assertOk()
            ->assertJsonPath('data.editor_data.blocks.0.data.content.0.0.content.0.content.0.text', 'Gabungan')
            ->assertJsonPath('data.editor_data.blocks.0.data.content.0.1.content.0.content.0.text', 'Vertikal')
            ->assertJsonPath('data.editor_data.blocks.0.data.content.1.0.attrs.colwidth.0', 120);
        $this->assertSame($table['blocks'][0]['data']['content'], $version->fresh()->editor_data_json['blocks'][0]['data']['content']);

        $unsafe = $table;
        $unsafe['blocks'][0]['data']['content'][0][0]['attrs']['backgroundColor'] = 'red;position:fixed';
        $this->patchJson("/api/v1/versions/{$version->guid}", [
            'editor_revision' => 2, 'editor_data' => $unsafe,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertSame(2, $version->fresh()->editor_revision);
    }

    public function test_table_border_change_invalidates_preview_and_requires_current_revision(): void
    {
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner, [
            'editor_data_json' => ['blocks' => [['type' => 'table', 'data' => ['withHeadings' => false, 'showBorders' => true, 'content' => [['Isi']]]]]],
            'preview_status' => PreviewStatus::Ready,
            'content_hash' => str_repeat('a', 64),
        ]);
        SignerAssignment::factory()->create(['version_guid' => $version->guid]);
        $changed = ['blocks' => [['type' => 'table', 'data' => ['withHeadings' => false, 'showBorders' => false, 'content' => [['Isi']]]]]];

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", [
            'editor_revision' => 1, 'editor_data' => $changed,
        ])->assertOk()->assertJsonPath('data.editor_data.blocks.0.data.showBorders', false)
            ->assertJsonPath('data.editor_revision', 2)
            ->assertJsonPath('data.preview_status', 'stale')
            ->assertJsonPath('data.content_hash', null);
        $this->patchJson("/api/v1/versions/{$version->guid}", [
            'editor_revision' => 1, 'editor_data' => $changed,
        ])->assertConflict()->assertJsonPath('error.code', 'EDITOR_REVISION_CONFLICT');

        $this->assertSame(false, $version->fresh()->editor_data_json['blocks'][0]['data']['showBorders']);
        $this->postJson("/api/v1/versions/{$version->guid}/publish")->assertConflict();
        $changed['blocks'][0]['data']['showBorders'] = true;
        $this->patchJson("/api/v1/versions/{$version->guid}", [
            'editor_revision' => 2, 'editor_data' => $changed,
        ])->assertOk()->assertJsonPath('data.editor_data.blocks.0.data.showBorders', true)
            ->assertJsonPath('data.editor_revision', 3);
    }

    public function test_table_border_choice_rejects_non_boolean_value(): void
    {
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner);

        $response = $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", [
            'editor_revision' => 1,
            'editor_data' => ['blocks' => [['type' => 'table', 'data' => [
                'showBorders' => 'false', 'content' => [['Isi']],
            ]]]],
        ])->assertUnprocessable();

        $this->assertSame('Pilihan garis tabel harus berupa boolean.', $response->json('error.errors')['editor_data.blocks.0.data.showBorders'][0]);
        $this->assertSame(1, $version->fresh()->editor_revision);
    }

    public function test_unsupported_table_structures_and_inline_format_report_their_location(): void
    {
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner);

        $complexCell = $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", [
            'editor_revision' => 1,
            'editor_data' => ['blocks' => [['type' => 'table', 'data' => [
                'content' => [[['text' => 'Gabung', 'colspan' => 2]]],
            ]]]],
        ])->assertUnprocessable();
        $this->assertSame('Sel tabel kompleks belum didukung; gunakan satu teks tanpa gambar atau gabungan sel.',
            $complexCell->json('error.errors')['editor_data.blocks.0.data.content.0.0'][0]);

        $attribute = $this->patchJson("/api/v1/versions/{$version->guid}", [
            'editor_revision' => 1,
            'editor_data' => ['blocks' => [['type' => 'table', 'data' => [
                'content' => [['Isi']], 'colWidths' => [100],
            ]]]],
        ])->assertUnprocessable();
        $this->assertSame('Atribut editor ini belum didukung pada PDF.',
            $attribute->json('error.errors')['editor_data.blocks.0.data.colWidths'][0]);

        $format = $this->patchJson("/api/v1/versions/{$version->guid}", [
            'editor_revision' => 1,
            'editor_data' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => '<s>coret</s>']]]],
        ])->assertUnprocessable();
        $this->assertSame('Format teks ini belum didukung pada PDF.',
            $format->json('error.errors')['editor_data.blocks.0.data.text'][0]);
        $this->assertSame(1, $version->fresh()->editor_revision);
    }

    public function test_unsupported_blocks_and_remote_images_are_rejected(): void
    {
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner);

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", ['editor_revision' => 1, 'editor_data' => ['blocks' => [['type' => 'embed', 'data' => []]]]])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['editor_data.blocks.0.type']]]);

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", ['editor_revision' => 1, 'editor_data' => ['blocks' => [
            ['type' => 'image', 'data' => ['file' => ['url' => 'http://169.254.169.254/latest']]],
        ]]])->assertUnprocessable()->assertJsonStructure(['error' => ['errors' => ['editor_data.blocks.0.data.file.url']]]);

        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", ['editor_revision' => 1, 'editor_data' => ['blocks' => [
            ['type' => 'image', 'data' => ['file' => ['url' => 'data:image/png;base64,'.base64_encode($png)], 'caption' => 'ok']],
        ]]])->assertOk();
    }

    public function test_block_count_limit_is_enforced(): void
    {
        config(['documents.editor.max_blocks' => 2]);
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner);
        $block = ['type' => 'paragraph', 'data' => ['text' => 'x']];

        $this->actingAs($owner)->patchJson("/api/v1/versions/{$version->guid}", ['editor_revision' => 1, 'editor_data' => ['blocks' => [$block, $block, $block]]])
            ->assertUnprocessable();
    }

    public function test_import_pdf_and_docx_are_stored_privately_and_replace_the_previous_source(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $version = $this->ownedDraft($owner, ['preview_status' => PreviewStatus::Ready]);

        $first = $this->actingAs($owner)->postJson("/api/v1/documents/{$version->document_guid}/imports", ['file' => $this->pdf()])
            ->assertAccepted()
            ->assertJsonPath('data.source_type', 'pdf')
            ->assertJsonPath('data.conversion_status', 'queued')
            ->assertJsonPath('data.preview_status', 'stale')
            ->assertJsonMissingPath('data.source_path');

        $oldPath = $version->fresh()->source_path;
        Storage::disk('local')->assertExists($oldPath);
        $this->assertStringStartsWith("documents/{$version->document_guid}/sources/", $oldPath);

        $this->actingAs($owner)->postJson("/api/v1/documents/{$version->document_guid}/imports", ['file' => $this->docx()])
            ->assertAccepted()->assertJsonPath('data.source_type', 'docx');

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
