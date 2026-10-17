<?php

namespace App\Services;

use App\Enums\SourceType;
use App\Exceptions\ApiException;
use App\Models\DocumentVersion;
use App\Models\SignaturePlaceholder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

class PdfDocumentRenderer
{
    private const PAGE_WIDTH_MM = 210;

    private const PAGE_HEIGHT_MM = 297;

    private const PAGE_SIDE_MARGIN_MM = 18;

    private const PAGE_MARGIN_MM = 20;

    private const DECORATED_PAGE_MARGIN_MM = 45;

    private const BODY_IMAGE_MAX_HEIGHT_MM = 220;

    private const CELL_IMAGE_MAX_HEIGHT_MM = 60;

    private const TABLE_CELL_PADDING_PT = 5;

    private const TABLE_BORDER_PT = 0.75;

    private const BODY_FIGURE_MARGIN_PT = 8;

    private const CELL_FIGURE_MARGIN_PT = 4;

    private const CAPTION_FONT_PT = 9;

    private const CAPTION_LINE_HEIGHT = 1.2;

    private const CAPTION_AVERAGE_CHAR_WIDTH_EM = 0.65;

    private const IMAGE_FIT_SAFETY_MM = 5;

    private const BODY_AVERAGE_CHAR_WIDTH_EM = 0.5;

    private const BODY_FONT_SIZE_PT = 11;

    private const BODY_LINE_HEIGHT = 1.5;

    private const PARAGRAPH_MARGIN_BOTTOM_PT = 10;

    private const TABLE_VERTICAL_MARGIN_PT = 8;

    public function __construct(
        private readonly EditorDataSanitizer $sanitizer,
        private readonly StoredPreviewSnapshot $snapshot,
        private readonly SignatureAssetService $assets,
    ) {}

    /** @return array{bytes: string, pages: int} */
    public function preview(DocumentVersion $version): array
    {
        if ($version->preview_source === 'upload') {
            if ($version->letterhead !== null || $version->footer !== null) {
                throw new ApiException(422, 'EDITOR_PREVIEW_REQUIRED', 'Kop atau footer memerlukan preview dari editor agar isi tidak tertimpa.');
            }
            if ($version->source_type !== SourceType::Pdf || $version->source_path === null) {
                throw new ApiException(422, 'EDITOR_PREVIEW_REQUIRED', 'Konversi sumber ke editor sebelum membuat preview.');
            }
            $bytes = Storage::disk(config('documents.disk'))->get($version->source_path);

            return ['bytes' => $bytes, 'pages' => $this->pageCount($bytes)];
        }

        $editor = $this->sanitizer->sanitize($version->editor_data_json ?? ['blocks' => []]);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml($this->html($editor, $version->letterhead !== null || $version->footer !== null), 'UTF-8');
        try {
            $pdf->render();
            $bytes = $pdf->output();
            $pages = $pdf->getCanvas()->get_page_count();
        } catch (Throwable) {
            throw new ApiException(422, 'PREVIEW_RENDER_FAILED', 'Isi editor tidak dapat dirender menjadi PDF.');
        }
        if (! str_starts_with($bytes, '%PDF-') || $pages < 1) {
            throw new ApiException(422, 'PREVIEW_RENDER_FAILED', 'Hasil preview PDF tidak valid.');
        }
        $this->assertPageLimit($pages);

        if ($version->letterhead !== null || $version->footer !== null) {
            $bytes = $this->decorate($bytes, $pages, $version);
        }

        return ['bytes' => $bytes, 'pages' => $pages];
    }

    public function finalize(DocumentVersion $version): string
    {
        $this->snapshot->verify($version);
        $bytes = Storage::disk(config('documents.disk'))->get($version->preview_pdf_path);
        $pdf = new Fpdi;
        try {
            $pageCount = $pdf->setSourceFile(StreamReader::createByString($bytes));
            if ($pageCount !== $version->preview_page_count) {
                throw ApiException::conflict('PREVIEW_PAGE_MISMATCH', 'Jumlah halaman preview berubah.');
            }
            $this->assertPageLimit($pageCount);
            $placements = [];
            foreach ($version->assignments as $assignment) {
                $signature = $assignment->signature;
                $asset = $signature?->asset;
                if ($assignment->status !== 'signed' || $signature === null || $asset === null
                    || $asset->user_guid !== $assignment->user_guid
                    || ! hash_equals($version->content_hash, $signature->version_content_hash)) {
                    throw ApiException::conflict('SIGNATURE_SNAPSHOT_MISMATCH', 'Persetujuan tanda tangan tidak lengkap atau tidak cocok.');
                }
                $this->assets->verify($asset);
                foreach ($assignment->placeholders as $placeholder) {
                    if ($placeholder->page_number < 1 || $placeholder->page_number > $pageCount) {
                        throw ApiException::conflict('PLACEHOLDER_PAGE_INVALID', 'Posisi tanda tangan tidak cocok dengan halaman PDF.');
                    }
                    $placements[$placeholder->page_number][] = [$placeholder, $asset];
                }
            }

            $temporary = [];
            try {
                for ($page = 1; $page <= $pageCount; $page++) {
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($template, 0, 0, $size['width'], $size['height']);
                    foreach ($placements[$page] ?? [] as [$placeholder, $asset]) {
                        $path = $temporary[$asset->guid] ?? null;
                        if ($path === null) {
                            $path = tempnam(sys_get_temp_dir(), 'dokumen-signature-');
                            if ($path === false) {
                                throw new ApiException(503, 'FINALIZATION_UNAVAILABLE', 'Ruang kerja PDF final tidak tersedia.');
                            }
                            file_put_contents($path, Storage::disk(config('documents.disk'))->get($asset->file_path));
                            $temporary[$asset->guid] = $path;
                        }
                        $this->placeImage($pdf, $path, $placeholder, $asset->width, $asset->height, $size['width'], $size['height']);
                    }
                }

                $final = $pdf->Output('S');
            } finally {
                foreach ($temporary as $path) {
                    @unlink($path);
                }
            }
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ApiException(422, 'FINALIZATION_RENDER_FAILED', 'PDF final tidak dapat dibentuk dari snapshot.');
        }
        if (! str_starts_with($final, '%PDF-')) {
            throw new ApiException(422, 'FINALIZATION_RENDER_FAILED', 'Hasil PDF final tidak valid.');
        }

        return $final;
    }

    private function placeImage(Fpdi $pdf, string $path, SignaturePlaceholder $placeholder, int $imageWidth, int $imageHeight, float $pageWidth, float $pageHeight): void
    {
        $boxWidth = $placeholder->width * $pageWidth;
        $boxHeight = $placeholder->height * $pageHeight;
        $ratio = $imageWidth / $imageHeight;
        $width = min($boxWidth, $boxHeight * $ratio);
        $height = $width / $ratio;
        $x = $placeholder->x * $pageWidth + ($boxWidth - $width) / 2;
        $y = $placeholder->y * $pageHeight + ($boxHeight - $height) / 2;
        $pdf->Image($path, $x, $y, $width, $height, 'PNG');
    }

    /** @param array<string, mixed> $editor */
    private function html(array $editor, bool $hasDecorations = false): string
    {
        $margin = $hasDecorations ? self::DECORATED_PAGE_MARGIN_MM : self::PAGE_MARGIN_MM;
        $contentHeight = self::PAGE_HEIGHT_MM - 2 * $margin;
        $contentWidth = self::PAGE_WIDTH_MM - 2 * self::PAGE_SIDE_MARGIN_MM;
        $body = '';
        foreach ($editor['blocks'] as $block) {
            $data = $block['data'];
            $body .= match ($block['type']) {
                'paragraph' => '<p>'.$data['text'].'</p>',
                'header' => '<h'.$data['level'].'>'.$data['text'].'</h'.$data['level'].'>',
                'list' => $this->listHtml($data['items'], $data['style']),
                'table' => $this->tableHtml($data, $contentWidth, $contentHeight),
                'image' => $this->imageHtml($data, $contentWidth, self::BODY_IMAGE_MAX_HEIGHT_MM, $contentHeight, self::BODY_FIGURE_MARGIN_PT)['html'],
                'delimiter' => '<hr class="editor-delimiter">',
            };
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'@page{size:A4 portrait;margin:'.$margin.'mm '.self::PAGE_SIDE_MARGIN_MM.'mm}'
            .'body{margin:0;padding:0;font-family:DejaVu Sans,sans-serif;font-size:'.self::BODY_FONT_SIZE_PT.'pt;line-height:'.self::BODY_LINE_HEIGHT.';color:#111}'
            .'[data-font-family="Arial"]{font-family:Helvetica,sans-serif}[data-font-family="Calibri"],[data-font-family="Aptos"]{font-family:DejaVu Sans,sans-serif}'
            .'[data-font-family="Times New Roman"]{font-family:Times,serif}[data-font-family="Georgia"]{font-family:DejaVu Serif,serif}'
            .'p{margin:0 0 '.self::PARAGRAPH_MARGIN_BOTTOM_PT.'pt}h1,h2,h3,h4,h5,h6{margin:12pt 0 7pt;line-height:'.self::BODY_LINE_HEIGHT.';page-break-after:avoid}'
            .'h1{font-size:2em}h2{font-size:1.5em}h3{font-size:1.17em}h4{font-size:1em}h5{font-size:.83em}h6{font-size:.67em}'
            .'ul,ol{margin:11pt 0;padding-left:24px}ul{list-style-type:disc}ul ul{list-style-type:circle}ul ul ul{list-style-type:square}ol{list-style-type:decimal}ol ol{list-style-type:lower-alpha}ol ol ol{list-style-type:lower-roman}'
            .'hr.editor-delimiter{display:block;width:100%;height:0;border:0;border-top:1px solid #777;margin:10pt 0;page-break-inside:avoid}'
            .'table{border-collapse:collapse;table-layout:fixed;width:100%;margin:'.self::TABLE_VERTICAL_MARGIN_PT.'pt 0}td,th{border:1px solid #777;padding:'.self::TABLE_CELL_PADDING_PT.'pt;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere}tr{page-break-inside:avoid}'
            .'table.table-without-borders td,table.table-without-borders th{border:0 none transparent}'
            .'figure{display:block;max-width:100%;margin:'.self::BODY_FIGURE_MARGIN_PT.'pt 0;text-align:center;page-break-inside:avoid}figure img{display:block;max-width:100%;margin:0 auto}'
            .'td p:last-child,th p:last-child{margin-bottom:0}td figure,th figure{margin:'.self::CELL_FIGURE_MARGIN_PT.'pt 0}td figure img,th figure img{max-height:'.self::CELL_IMAGE_MAX_HEIGHT_MM.'mm}'
            .'figcaption{font-size:'.self::CAPTION_FONT_PT.'pt;line-height:'.self::CAPTION_LINE_HEIGHT.';color:#555;text-align:center}li{margin-bottom:3pt}'
            .'</style></head><body>'.$body.'</body></html>';
    }

    /** @param list<mixed> $items */
    private function listHtml(array $items, string $style): string
    {
        $tag = $style === 'ordered' ? 'ol' : 'ul';
        $html = '<'.$tag.'>';
        foreach ($items as $item) {
            $html .= '<li>'.(is_string($item) ? $item : $item['content']);
            if (is_array($item) && $item['items'] !== []) {
                $html .= $this->listHtml($item['items'], $style);
            }
            $html .= '</li>';
        }

        return $html.'</'.$tag.'>';
    }

    /** @param array<string, mixed> $data */
    private function tableHtml(array $data, float $contentWidthMm, float $contentHeightMm): string
    {
        $grid = [];
        $positions = [];
        $widths = [];
        $declaredWidths = [];
        $hasColumnWidths = false;
        $columns = 0;
        foreach ($data['content'] as $rowIndex => $row) {
            $column = 0;
            foreach ($row as $cellIndex => $value) {
                while (isset($grid[$rowIndex][$column])) {
                    $column++;
                }
                $attrs = is_array($value) && array_key_exists('attrs', $value) ? $value['attrs'] : [];
                $colspan = $attrs['colspan'] ?? 1;
                $rowspan = $attrs['rowspan'] ?? 1;
                $positions[$rowIndex][$cellIndex] = ['column' => $column, 'attrs' => $attrs];
                for ($rowOffset = 0; $rowOffset < $rowspan; $rowOffset++) {
                    for ($columnOffset = 0; $columnOffset < $colspan; $columnOffset++) {
                        $grid[$rowIndex + $rowOffset][$column + $columnOffset] = true;
                    }
                }
                foreach ($attrs['colwidth'] ?? [] as $offset => $width) {
                    $hasColumnWidths = true;
                    $widths[$column + $offset] = $width;
                    $declaredWidths[] = $width;
                }
                $column += $colspan;
                $columns = max($columns, $column);
            }
        }
        $fallbackWidth = $declaredWidths === [] ? 1 : array_sum($declaredWidths) / count($declaredWidths);
        for ($column = 0; $column < $columns; $column++) {
            $widths[$column] ??= $fallbackWidth;
        }
        ksort($widths);
        $totalWidth = array_sum($widths);
        $widthsMm = array_map(fn (float|int $width): float => $contentWidthMm * $width / $totalWidth, $widths);
        $html = ($data['showBorders'] ?? true) ? '<table>' : '<table class="table-without-borders">';
        $html .= '<colgroup>';
        foreach ($widthsMm as $width) {
            $html .= '<col width="'.round($width * 96 / 25.4).'">';
        }
        $html .= '</colgroup>';
        if ($hasColumnWidths) {
            $html .= '<tr>';
            foreach ($widths as $width) {
                $html .= '<td style="width:'.($width / $totalWidth * 100).'%;height:0;padding:0;border:0;font-size:0;line-height:0"></td>';
            }
            $html .= '</tr>';
        }
        $colors = [
            'gray' => '#f1f5f9', 'blue' => '#dbeafe', 'green' => '#dcfce7', 'yellow' => '#fef3c7',
            'orange' => '#ffedd5', 'red' => '#fee2e2', 'purple' => '#f3e8ff',
        ];
        foreach ($data['content'] as $rowIndex => $row) {
            $rowHeight = $data['rowMinHeights'][$rowIndex] ?? null;
            $html .= '<tr>';
            $rowHeightPt = 0;
            foreach ($row as $cellIndex => $value) {
                $cell = $positions[$rowIndex][$cellIndex];
                $attrs = $cell['attrs'];
                $colspan = $attrs['colspan'] ?? 1;
                $rowspan = $attrs['rowspan'] ?? 1;
                $spanWidthMm = array_sum(array_slice($widthsMm, $cell['column'], $colspan));
                $cellWidthMm = max(8, $spanWidthMm - $this->ptToMm(self::TABLE_CELL_PADDING_PT * 2 + self::TABLE_BORDER_PT * 2));
                $tag = $data['withHeadings'] && $rowIndex === 0 ? 'th' : 'td';
                $styles = [];
                if (isset($attrs['align'])) {
                    $styles[] = 'text-align:'.$attrs['align'];
                }
                if (isset($attrs['verticalAlign'])) {
                    $styles[] = 'vertical-align:'.($attrs['verticalAlign'] === 'middle' ? 'middle' : 'bottom');
                }
                if (isset($attrs['backgroundColor'])) {
                    $styles[] = 'background-color:'.$colors[$attrs['backgroundColor']];
                }
                $minimumCellHeight = array_sum(array_map(
                    fn ($height): int => $height ?? 0,
                    array_slice($data['rowMinHeights'] ?? [], $rowIndex, $rowspan),
                ));
                if ($minimumCellHeight > 0) {
                    $styles[] = 'height:'.$minimumCellHeight.'px';
                }
                $showBorder = $attrs['border'] ?? ($data['showBorders'] ?? true);
                if (array_key_exists('border', $attrs)) {
                    $styles[] = $showBorder ? 'border:1px solid #777' : 'border:0 none transparent';
                }
                $html .= '<'.$tag.' width="'.round($spanWidthMm * 96 / 25.4).'"'.($colspan > 1 ? ' colspan="'.$colspan.'"' : '').($rowspan > 1 ? ' rowspan="'.$rowspan.'"' : '')
                    .($styles === [] ? '' : ' style="'.implode(';', $styles).'"').'>';
                $cellHeightPt = 2 * self::TABLE_CELL_PADDING_PT + ($showBorder ? 2 * self::TABLE_BORDER_PT : 0);
                $cellContent = is_array($value) && array_key_exists('attrs', $value) ? $value['content'] : $value;
                if (is_string($cellContent)) {
                    $html .= '<p>'.$cellContent.'</p>';
                    $cellHeightPt += $this->textLines($cellContent, $cellWidthMm) * self::BODY_FONT_SIZE_PT * self::BODY_LINE_HEIGHT;
                } else {
                    $lastNode = count($cellContent) - 1;
                    foreach ($cellContent as $nodeIndex => $node) {
                        if ($node['type'] === 'image') {
                            $image = $this->imageHtml($node, $cellWidthMm, self::CELL_IMAGE_MAX_HEIGHT_MM, $contentHeightMm, self::CELL_FIGURE_MARGIN_PT);
                            $html .= $image['html'];
                            $cellHeightPt += $this->mmToPt($image['heightMm']);
                        } else {
                            $html .= '<p>'.$this->cellParagraphHtml($node['content']).'</p>';
                            $cellHeightPt += $this->textLines($this->cellText($node['content']), $cellWidthMm) * self::BODY_FONT_SIZE_PT * self::BODY_LINE_HEIGHT;
                            if ($nodeIndex < $lastNode) {
                                $cellHeightPt += self::PARAGRAPH_MARGIN_BOTTOM_PT;
                            }
                        }
                    }
                }
                $html .= '</'.$tag.'>';
                $rowHeightPt = max($rowHeightPt, $cellHeightPt / $rowspan);
            }
            $html .= '</tr>';
            $rowHeightPt = max($rowHeightPt, ($rowHeight ?? 0) * 0.75);
            if ($this->mmToPt($contentHeightMm - self::IMAGE_FIT_SAFETY_MM) < $rowHeightPt + 2 * self::TABLE_VERTICAL_MARGIN_PT) {
                throw new ApiException(422, 'PREVIEW_RENDER_FAILED', 'Satu baris tabel terlalu tinggi untuk satu area halaman. Pendekkan isi baris lalu buat preview lagi.');
            }
        }

        return $html.'</table>';
    }

    /** @param array<string, mixed> $data */
    /** @return array{html: string, heightMm: float} */
    private function imageHtml(array $data, float $maxWidthMm, float $maxImageHeightMm, float $contentHeightMm, float $figureMarginPt): array
    {
        $url = $data['file']['url'];
        $bytes = base64_decode(substr($url, strpos($url, ',') + 1), true);
        $dimensions = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($dimensions === false) {
            throw new ApiException(422, 'PREVIEW_RENDER_FAILED', 'Gambar editor tidak dapat dibaca untuk membuat preview.');
        }

        $intrinsicWidthMm = $dimensions[0] * 25.4 / 96;
        $intrinsicHeightMm = $dimensions[1] * 25.4 / 96;
        $caption = trim($data['caption']);
        $captionHeightMm = $this->captionHeightMm($caption, $maxWidthMm);
        $availableImageHeightMm = min(
            $maxImageHeightMm,
            $contentHeightMm - $captionHeightMm - $this->ptToMm($figureMarginPt * 2) - self::IMAGE_FIT_SAFETY_MM,
        );
        if ($availableImageHeightMm <= 0) {
            throw new ApiException(422, 'PREVIEW_RENDER_FAILED', 'Caption gambar terlalu tinggi untuk area halaman. Pendekkan caption lalu buat preview lagi.');
        }

        $scale = min(1, $maxWidthMm / $intrinsicWidthMm, $availableImageHeightMm / $intrinsicHeightMm);
        $widthMm = floor($intrinsicWidthMm * $scale * 1000) / 1000;
        $heightMm = floor($intrinsicHeightMm * $scale * 1000) / 1000;
        $captionHtml = $caption === '' ? '' : '<figcaption>'.$caption.'</figcaption>';

        return [
            'html' => '<figure><img src="'.$url.'" alt="" style="width:'.$widthMm.'mm;height:'.$heightMm.'mm"/>'.$captionHtml.'</figure>',
            'heightMm' => $heightMm + $captionHeightMm + $this->ptToMm($figureMarginPt * 2),
        ];
    }

    /** @param list<array<string, mixed>> $content */
    private function cellText(array $content): string
    {
        return implode('', array_map(fn (array $inline): string => $inline['type'] === 'hardBreak' ? "\n" : $inline['text'], $content));
    }

    private function textLines(string $text, float $widthMm): int
    {
        // shortcut: wrapping uses average glyph widths; use Dompdf frame measurements if real fixtures expose a mismatch.
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $charactersPerLine = max(1, (int) floor($widthMm * 72 / 25.4 / (self::BODY_FONT_SIZE_PT * self::BODY_AVERAGE_CHAR_WIDTH_EM)));
        $lines = 0;
        foreach (preg_split('/\R/u', $text) ?: [''] as $line) {
            $lines += max(1, (int) ceil(mb_strwidth($line, 'UTF-8') / $charactersPerLine));
        }

        return $lines;
    }

    private function captionHeightMm(string $caption, float $widthMm): float
    {
        if ($caption === '') {
            return 0;
        }

        $caption = preg_replace('#<br\s*/?>#i', "\n", $caption) ?? $caption;
        $text = html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lineWidth = max(1, (int) floor($widthMm * 72 / 25.4 / (self::CAPTION_FONT_PT * self::CAPTION_AVERAGE_CHAR_WIDTH_EM)));
        $lines = 0;
        foreach (preg_split('/\R/u', $text) ?: [''] as $line) {
            $lines += max(1, (int) ceil(mb_strwidth($line, 'UTF-8') / $lineWidth));
        }

        return $this->ptToMm($lines * self::CAPTION_FONT_PT * self::CAPTION_LINE_HEIGHT);
    }

    private function ptToMm(float $points): float
    {
        return $points * 25.4 / 72;
    }

    private function mmToPt(float $millimeters): float
    {
        return $millimeters * 72 / 25.4;
    }

    /** @param list<array<string, mixed>> $content */
    private function cellParagraphHtml(array $content): string
    {
        $html = '';
        foreach ($content as $inline) {
            if ($inline['type'] === 'hardBreak') {
                $html .= '<br>';

                continue;
            }
            $text = e($inline['text']);
            foreach ($inline['marks'] ?? [] as $mark) {
                $tag = match ($mark['type']) {
                    'bold' => 'b', 'italic' => 'i', 'underline' => 'u', 'code' => 'code', 'highlight' => 'mark', 'link' => 'a', 'fontFamily' => 'span',
                };
                $attributes = match ($mark['type']) {
                    'link' => ' href="'.e($mark['attrs']['href']).'" rel="noopener noreferrer"',
                    'fontFamily' => ' data-font-family="'.e($mark['attrs']['fontFamily']).'"',
                    default => '',
                };
                $text = '<'.$tag.$attributes.'>'.$text.'</'.$tag.'>';
            }
            $html .= $text;
        }

        return $html;
    }

    private function pageCount(string $bytes): int
    {
        try {
            $pdf = new Fpdi;
            $pages = $pdf->setSourceFile(StreamReader::createByString($bytes));
        } catch (Throwable) {
            throw new ApiException(422, 'PDF_SOURCE_INVALID', 'PDF sumber tidak dapat dibaca.');
        }
        $this->assertPageLimit($pages);

        return $pages;
    }

    private function decorate(string $bytes, int $pages, DocumentVersion $version): string
    {
        foreach (['letterhead', 'footer'] as $kind) {
            $decoration = $version->{$kind};
            if ($decoration === null || $decoration['pages']['mode'] === 'all') {
                continue;
            }
            foreach ($decoration['pages']['ranges'] as [$start, $end]) {
                if ($end > $pages) {
                    throw new ApiException(422, 'DECORATION_PAGE_OUT_OF_RANGE', "Rentang {$kind} {$start}-{$end} melebihi {$pages} halaman preview.");
                }
            }
        }

        $pdf = new Fpdi;
        $temporary = [];
        try {
            $pdf->setSourceFile(StreamReader::createByString($bytes));
            foreach (['letterhead', 'footer'] as $kind) {
                $decoration = $version->{$kind};
                if ($decoration === null) {
                    continue;
                }
                $path = tempnam(sys_get_temp_dir(), 'dokumen-decoration-');
                if ($path === false) {
                    throw new ApiException(503, 'PREVIEW_UNAVAILABLE', 'Ruang kerja preview tidak tersedia.');
                }
                $temporary[$kind] = $path;
                $image = Storage::disk(config('documents.disk'))->get($decoration['path']);
                if (file_put_contents($path, $image) === false) {
                    throw new ApiException(503, 'PREVIEW_UNAVAILABLE', 'Ruang kerja preview tidak tersedia.');
                }
            }
            for ($page = 1; $page <= $pages; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template, 0, 0, $size['width'], $size['height']);
                foreach (['letterhead', 'footer'] as $kind) {
                    $decoration = $version->{$kind};
                    if ($decoration === null || ! $this->selectedPage($decoration['pages'], $page)) {
                        continue;
                    }
                    $width = min($size['width'] - 36, $decoration['width'] * 25.4 / 96, 30 * $decoration['width'] / $decoration['height']);
                    $height = $width * $decoration['height'] / $decoration['width'];
                    $x = ($size['width'] - $width) / 2;
                    $y = $kind === 'letterhead' ? 7 : $size['height'] - 7 - $height;
                    $pdf->Image($temporary[$kind], $x, $y, $width, $height, 'PNG');
                }
            }

            return $pdf->Output('S');
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ApiException(422, 'DECORATION_RENDER_FAILED', 'Kop atau footer tidak dapat dirender pada PDF.');
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }
    }

    /** @param array<string, mixed> $selection */
    private function selectedPage(array $selection, int $page): bool
    {
        if ($selection['mode'] === 'all') {
            return true;
        }
        foreach ($selection['ranges'] as [$start, $end]) {
            if ($page >= $start && $page <= $end) {
                return true;
            }
        }

        return false;
    }

    private function assertPageLimit(int $pages): void
    {
        if ($pages < 1 || $pages > (int) config('documents.processing.max_pages')) {
            throw new ApiException(422, 'DOCUMENT_PAGE_LIMIT', 'Jumlah halaman melewati batas.');
        }
    }
}
