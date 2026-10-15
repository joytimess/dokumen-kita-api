<?php

namespace App\Services;

use App\Enums\SourceType;
use App\Exceptions\ApiException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Config as PdfParserConfig;
use Smalot\PdfParser\Parser as PdfParser;
use Smalot\PdfParser\XObject\Image as PdfImage;
use Symfony\Component\Process\Process;
use ZipArchive;

class SourceDocumentConverter
{
    public function __construct(private readonly EditorDataSanitizer $sanitizer) {}

    /** @return array{editor_data: array<string, mixed>, pages: int, warnings: list<string>} */
    public function convert(SourceType $type, string $sourcePath): array
    {
        $disk = Storage::disk(config('documents.disk'));
        $stream = $disk->readStream($sourcePath);
        if (! is_resource($stream)) {
            throw ApiException::conflict('SOURCE_UNAVAILABLE', 'Berkas sumber tidak tersedia.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'dokumen-source-');
        if ($temporary === false) {
            fclose($stream);
            throw new ApiException(503, 'CONVERSION_UNAVAILABLE', 'Ruang kerja konversi tidak tersedia.');
        }

        try {
            $local = fopen($temporary, 'wb');
            if (! is_resource($local)) {
                throw new ApiException(503, 'CONVERSION_UNAVAILABLE', 'Ruang kerja konversi tidak tersedia.');
            }
            try {
                stream_copy_to_stream($stream, $local);
            } finally {
                fclose($local);
            }

            $result = $type === SourceType::Pdf ? $this->convertPdf($temporary) : $this->convertDocx($temporary);
            try {
                $result['editor_data'] = $this->sanitizer->sanitize($result['editor_data']);
            } catch (ValidationException $exception) {
                $withoutImages = array_values(array_filter(
                    $result['editor_data']['blocks'], fn (array $block): bool => $block['type'] !== 'image',
                ));
                if (count($withoutImages) === count($result['editor_data']['blocks'])) {
                    throw $exception;
                }
                $result['editor_data'] = $this->sanitizer->sanitize(['blocks' => $withoutImages]);
                $result['warnings'][] = 'IMPORT_IMAGES_EXCEEDED_EDITOR_LIMIT';
            }

            return $result;
        } finally {
            fclose($stream);
            @unlink($temporary);
        }
    }

    /** @return array{editor_data: array<string, mixed>, pages: int, warnings: list<string>} */
    private function convertPdf(string $path): array
    {
        $binary = (string) config('documents.processing.pdftotext_binary');
        $process = new Process([$binary, '-layout', '-enc', 'UTF-8', $path, '-']);
        $process->setTimeout((int) config('documents.processing.binary_timeout'));
        try {
            $process->run();
            $fallback = ! $process->isSuccessful();
        } catch (\Throwable) {
            $fallback = true;
        }
        $pages = $fallback ? $this->phpPdfPages($path) : (preg_split('/\f/', $process->getOutput()) ?: []);
        while (count($pages) > 1 && trim(end($pages)) === '') {
            array_pop($pages);
        }
        if (count($pages) > (int) config('documents.processing.max_pages')) {
            throw new ApiException(422, 'DOCUMENT_PAGE_LIMIT', 'Jumlah halaman melewati batas.');
        }
        if (trim(implode('', $pages)) === '') {
            throw new ApiException(422, 'PDF_TEXT_LAYER_MISSING', 'PDF tidak memiliki teks yang dapat diedit; OCR belum tersedia.');
        }

        $warnings = ['PDF_LAYOUT_APPROXIMATE', 'PDF_GRAPHICS_REQUIRE_REVIEW', 'PDF_TABLES_REQUIRE_REVIEW'];
        $images = $this->pdfImages($path, count($pages), $warnings);
        $blocks = [];
        foreach ($pages as $pageIndex => $page) {
            $chunks = preg_split('/(?:\r?\n){2,}/', trim($page)) ?: [];
            foreach ($chunks as $chunk) {
                $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $chunk) ?: []), fn (string $line): bool => $line !== ''));
                if ($lines === []) {
                    continue;
                }
                $table = array_map(fn (string $line): array => preg_split('/\s{2,}/u', $line) ?: [], $lines);
                $columnCount = count($table[0]);
                if (count($lines) > 1 && $columnCount > 1 && $columnCount <= config('documents.editor.max_table_columns')
                    && count($lines) <= config('documents.editor.max_table_rows')
                    && count(array_filter($table, fn (array $row): bool => count($row) === $columnCount)) === count($table)) {
                    $blocks[] = ['type' => 'table', 'data' => ['withHeadings' => false, 'content' => $table]];
                } else {
                    $blocks[] = ['type' => 'paragraph', 'data' => ['text' => e(implode(' ', $lines))]];
                }
            }
            array_push($blocks, ...($images[$pageIndex] ?? []));
        }

        if ($fallback) {
            $warnings[] = 'PDF_PHP_FALLBACK';
        }

        return [
            'editor_data' => ['blocks' => $blocks],
            'pages' => max(1, count($pages)),
            'warnings' => $warnings,
        ];
    }

    /** @param list<string> $warnings
     * @return array<int, list<array<string, mixed>>>
     */
    private function pdfImages(string $path, int $pageCount, array &$warnings): array
    {
        try {
            $config = new PdfParserConfig;
            $config->setDecodeMemoryLimit(32 * 1024 * 1024);
            $document = (new PdfParser([], $config))->parseFile($path);
            $pages = $document->getPages();
            $images = [];
            foreach ($pages as $pageIndex => $page) {
                if ($pageIndex >= $pageCount) {
                    break;
                }
                $seen = [];
                $objects = $page->getXObjects();
                if ($objects === [] && count($pages) === 1) {
                    $objects = $document->getObjectsByType('XObject', 'Image');
                    if ($objects !== []) {
                        $warnings[] = 'PDF_IMAGE_POSITION_APPROXIMATE';
                    }
                }
                foreach ($objects as $object) {
                    if (! $object instanceof PdfImage || isset($seen[spl_object_id($object)])) {
                        continue;
                    }
                    $seen[spl_object_id($object)] = true;
                    $block = $this->imageBlock($object->getContent());
                    if ($block === null) {
                        $warnings[] = 'PDF_IMAGE_SKIPPED';

                        continue;
                    }
                    $images[$pageIndex][] = $block;
                }
            }

            if ($images === [] && $document->hasObjectsByType('XObject', 'Image')) {
                $warnings[] = 'PDF_IMAGE_SKIPPED';
            }

            return $images;
        } catch (\Throwable) {
            $warnings[] = 'PDF_IMAGE_EXTRACTION_FAILED';

            return [];
        }
    }

    /** @return list<string> */
    private function phpPdfPages(string $path): array
    {
        try {
            $config = new PdfParserConfig;
            $config->setDecodeMemoryLimit(32 * 1024 * 1024);
            $document = (new PdfParser([], $config))->parseFile($path);
            $pages = $document->getPages();
            if (count($pages) > (int) config('documents.processing.max_pages')) {
                throw new ApiException(422, 'DOCUMENT_PAGE_LIMIT', 'Jumlah halaman melewati batas.');
            }

            return array_map(fn ($page): string => $page->getText(), $pages);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ApiException(422, 'PDF_CONVERSION_FAILED', 'PDF tidak dapat dikonversi menjadi teks editor.');
        }
    }

    /** @return array{editor_data: array<string, mixed>, pages: int, warnings: list<string>} */
    private function convertDocx(string $path): array
    {
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            throw new ApiException(422, 'DOCX_CONVERSION_FAILED', 'DOCX tidak dapat dibaca.');
        }

        try {
            $stats = $archive->statName('word/document.xml');
            if (($stats['size'] ?? 0) > 5 * 1024 * 1024) {
                throw new ApiException(422, 'CONVERSION_LIMIT', 'Isi DOCX melewati batas konversi.');
            }
            $xml = $archive->getFromName('word/document.xml');
            if (! is_string($xml)) {
                throw new ApiException(422, 'DOCX_CONVERSION_FAILED', 'Isi DOCX tidak tersedia.');
            }
            $document = new DOMDocument;
            if (! @$document->loadXML($xml, LIBXML_NONET)) {
                throw new ApiException(422, 'DOCX_CONVERSION_FAILED', 'Struktur DOCX tidak valid.');
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $blocks = [];
            foreach ($xpath->query('/w:document/w:body/*') ?: [] as $element) {
                if (! $element instanceof DOMElement) {
                    continue;
                }
                if ($element->localName === 'tbl') {
                    $rows = [];
                    foreach ($xpath->query('./w:tr', $element) ?: [] as $row) {
                        $cells = [];
                        foreach ($xpath->query('./w:tc', $row) ?: [] as $cell) {
                            $cells[] = e($this->xmlText($xpath, $cell));
                        }
                        $rows[] = $cells;
                    }
                    $blocks[] = ['type' => 'table', 'data' => ['withHeadings' => false, 'content' => $rows]];
                } elseif ($element->localName === 'p') {
                    $content = $this->xmlText($xpath, $element);
                    if ($content === '') {
                        continue;
                    }
                    $style = $xpath->evaluate('string(./w:pPr/w:pStyle/@w:val)', $element);
                    $blocks[] = preg_match('/^Heading([1-6])$/i', $style, $match)
                        ? ['type' => 'header', 'data' => ['text' => e($content), 'level' => (int) $match[1]]]
                        : ['type' => 'paragraph', 'data' => ['text' => e($content)]];
                }
            }

            $warnings = ['DOCX_LAYOUT_APPROXIMATE'];
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $name = (string) $archive->getNameIndex($index);
                if (str_starts_with($name, 'word/media/') && ! str_ends_with($name, '/')) {
                    $block = $this->imageBlock($archive->getFromIndex($index));
                    if ($block === null) {
                        $warnings[] = 'DOCX_IMAGE_SKIPPED';
                    } else {
                        $blocks[] = $block;
                        $warnings[] = 'DOCX_IMAGE_POSITION_APPROXIMATE';
                    }
                }
            }

            return ['editor_data' => ['blocks' => $blocks], 'pages' => 1, 'warnings' => array_values(array_unique($warnings))];
        } finally {
            $archive->close();
        }
    }

    /** @return array{type: string, data: array<string, mixed>}|null */
    private function imageBlock(?string $bytes): ?array
    {
        if ($bytes === null || strlen($bytes) > 500_000) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return null;
        }
        $mime = match ($info[2]) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_WEBP => 'webp',
        };
        $url = 'data:image/'.$mime.';base64,'.base64_encode($bytes);
        if (strlen($url) > (int) config('documents.editor.max_image_data_length')) {
            return null;
        }

        return ['type' => 'image', 'data' => ['file' => ['url' => $url], 'caption' => '']];
    }

    private function xmlText(DOMXPath $xpath, \DOMNode $node): string
    {
        $parts = [];
        foreach ($xpath->query('.//w:t', $node) ?: [] as $text) {
            $parts[] = $text->textContent;
        }

        return trim(implode('', $parts));
    }
}
