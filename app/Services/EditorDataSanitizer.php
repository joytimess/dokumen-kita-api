<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Validates Editor.js JSON against the allowed block set and strips unsafe markup.
 *
 * Only inline formatting (b, i, u, strong, em, mark, code, br, and http/https/mailto links) survives.
 * Images must be inline base64 png/jpeg/webp: the renderer never fetches remote URLs.
 */
class EditorDataSanitizer
{
    private const ALLOWED_TYPES = ['paragraph', 'header', 'list', 'table', 'image', 'delimiter'];

    private const FONT_FAMILIES = ['Arial', 'Calibri', 'Times New Roman', 'Aptos', 'Georgia'];

    private const INLINE_TAGS = '<b><i><u><strong><em><mark><code><br><a><span>';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function sanitize(array $data): array
    {
        $blocks = $data['blocks'] ?? null;

        if (! is_array($blocks) || ! array_is_list($blocks)) {
            $this->fail('editor_data.blocks', 'Blok dokumen harus berupa daftar.');
        }

        if (count($blocks) > config('documents.editor.max_blocks')) {
            $this->fail('editor_data.blocks', 'Jumlah blok melebihi batas.');
        }

        $clean = ['blocks' => []];

        foreach ($blocks as $index => $block) {
            $clean['blocks'][] = $this->sanitizeBlock($block, "editor_data.blocks.$index");
        }

        if (isset($data['time']) && is_int($data['time'])) {
            $clean['time'] = $data['time'];
        }

        if (isset($data['version']) && is_string($data['version']) && strlen($data['version']) <= 32) {
            $clean['version'] = $data['version'];
        }

        if (strlen((string) json_encode($clean)) > config('documents.editor.max_bytes')) {
            $this->fail('editor_data', 'Ukuran dokumen melebihi batas.');
        }

        return $clean;
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    private function sanitizeBlock(mixed $block, string $path): array
    {
        $type = is_array($block) ? ($block['type'] ?? null) : null;

        if (! in_array($type, self::ALLOWED_TYPES, true) || ! is_array($block['data'] ?? null)) {
            $this->fail("$path.type", 'Tipe blok tidak didukung.');
        }

        $data = $block['data'];
        $allowedKeys = match ($type) {
            'paragraph' => ['text'],
            'header' => ['text', 'level'],
            'list' => ['style', 'items'],
            'table' => ['withHeadings', 'showBorders', 'content'],
            'image' => ['file', 'caption'],
            'delimiter' => [],
        };
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                $this->fail("$path.data.$key", 'Atribut editor ini belum didukung pada PDF.');
            }
        }

        return ['type' => $type, 'data' => match ($type) {
            'paragraph' => ['text' => $this->text($data['text'] ?? '', "$path.data.text")],
            'header' => [
                'text' => $this->text($data['text'] ?? '', "$path.data.text"),
                'level' => min(6, max(1, (int) ($data['level'] ?? 2))),
            ],
            'list' => $this->sanitizeList($data, $path),
            'table' => $this->sanitizeTable($data, $path),
            'image' => $this->sanitizeImage($data, $path),
            'delimiter' => [],
        }];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeList(array $data, string $path): array
    {
        $items = $data['items'] ?? null;

        if (! is_array($items)) {
            $this->fail("$path.data.items", 'Item daftar tidak valid.');
        }

        $style = ($data['style'] ?? 'unordered') === 'ordered' ? 'ordered' : 'unordered';

        return ['style' => $style, 'items' => $this->sanitizeListItems($items, "$path.data.items", 1)];
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return list<mixed>
     */
    private function sanitizeListItems(array $items, string $path, int $depth): array
    {
        if ($depth > config('documents.editor.max_list_depth') || count($items) > config('documents.editor.max_list_items')) {
            $this->fail($path, 'Daftar terlalu dalam atau terlalu panjang.');
        }

        $clean = [];

        foreach (array_values($items) as $i => $item) {
            if (is_string($item)) {
                $clean[] = $this->text($item, "$path.$i");

                continue;
            }

            if (! is_array($item) || ! is_string($item['content'] ?? null)) {
                $this->fail("$path.$i", 'Item daftar tidak valid.');
            }

            $clean[] = [
                'content' => $this->text($item['content'], "$path.$i.content"),
                'items' => is_array($item['items'] ?? null) ? $this->sanitizeListItems($item['items'], "$path.$i.items", $depth + 1) : [],
            ];
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeTable(array $data, string $path): array
    {
        if (array_key_exists('withHeadings', $data) && ! is_bool($data['withHeadings'])) {
            $this->fail("$path.data.withHeadings", 'Pilihan kepala tabel harus berupa boolean.');
        }
        if (array_key_exists('showBorders', $data) && ! is_bool($data['showBorders'])) {
            $this->fail("$path.data.showBorders", 'Pilihan garis tabel harus berupa boolean.');
        }

        $rows = $data['content'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > config('documents.editor.max_table_rows')) {
            $this->fail("$path.data.content", 'Tabel tidak valid atau terlalu besar.');
        }

        $clean = [];

        foreach (array_values($rows) as $r => $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) > config('documents.editor.max_table_columns')) {
                $this->fail("$path.data.content.$r", 'Baris tabel tidak valid atau terlalu lebar.');
            }

            $cleanRow = [];
            foreach (array_values($row) as $column => $cell) {
                $cellPath = "$path.data.content.$r.$column";
                if (is_array($cell)) {
                    if (! array_is_list($cell)) {
                        $this->fail($cellPath, 'Penggabungan sel tabel belum didukung.');
                    }
                    if (count($cell) > config('documents.editor.max_blocks')) {
                        $this->fail($cellPath, 'Jumlah blok sel melebihi batas.');
                    }
                    $cleanRow[] = array_map(fn (mixed $node, int $i): array => $this->sanitizeCellNode($node, "$cellPath.$i"), $cell, array_keys($cell));

                    continue;
                }
                if (is_string($cell) && preg_match('/<\/?(?:p|div|img|table)\b/i', $cell)) {
                    $this->fail($cellPath, 'HTML blok atau gambar mentah tidak didukung di sel tabel.');
                }
                $cleanRow[] = $this->text($cell, $cellPath);
            }
            $clean[] = $cleanRow;
        }

        return [
            'withHeadings' => (bool) ($data['withHeadings'] ?? false),
            'showBorders' => $data['showBorders'] ?? true,
            'content' => $clean,
        ];
    }

    /** @return array<string, mixed> */
    private function sanitizeCellNode(mixed $node, string $path): array
    {
        if (! is_array($node) || ! in_array($node['type'] ?? null, ['paragraph', 'image'], true)) {
            $this->fail($path, 'Tipe blok sel tidak didukung.');
        }
        $type = $node['type'];
        foreach (array_keys($node) as $key) {
            if (! in_array($key, $type === 'image' ? ['type', 'file', 'caption'] : ['type', 'content'], true)) {
                $this->fail("$path.$key", 'Atribut blok sel tidak didukung.');
            }
        }
        if ($type === 'image') {
            if (! is_array($node['file'] ?? null) || count($node['file']) !== 1 || ! array_key_exists('url', $node['file'])) {
                $this->fail("$path.file", 'Atribut gambar sel tidak didukung.');
            }
            $image = $this->sanitizeImage($node, $path);

            return ['type' => 'image', ...$image];
        }
        $content = $node['content'] ?? null;
        if (! is_array($content) || ! array_is_list($content) || count($content) > config('documents.editor.max_text_length')) {
            $this->fail("$path.content", 'Isi paragraf sel tidak valid atau terlalu panjang.');
        }
        $clean = [];
        $length = 0;
        foreach ($content as $i => $inline) {
            $inlinePath = "$path.content.$i";
            if (! is_array($inline) || ! in_array($inline['type'] ?? null, ['text', 'hardBreak'], true)) {
                $this->fail($inlinePath, 'Tipe teks sel tidak didukung.');
            }
            if ($inline['type'] === 'hardBreak') {
                if (count($inline) !== 1) {
                    $this->fail($inlinePath, 'Atribut baris baru tidak didukung.');
                }
                $clean[] = ['type' => 'hardBreak'];

                continue;
            }
            foreach (array_keys($inline) as $key) {
                if (! in_array($key, ['type', 'text', 'marks'], true)) {
                    $this->fail("$inlinePath.$key", 'Atribut teks sel tidak didukung.');
                }
            }
            if (! is_string($inline['text'] ?? null)) {
                $this->fail("$inlinePath.text", 'Teks sel tidak valid.');
            }
            $length += mb_strlen($inline['text']);
            if ($length > config('documents.editor.max_text_length')) {
                $this->fail("$path.content", 'Teks sel terlalu panjang.');
            }
            $text = ['type' => 'text', 'text' => $inline['text']];
            if (isset($inline['marks'])) {
                if (! is_array($inline['marks']) || ! array_is_list($inline['marks'])) {
                    $this->fail("$inlinePath.marks", 'Format teks sel tidak valid.');
                }
                $text['marks'] = [];
                foreach ($inline['marks'] as $m => $mark) {
                    $markPath = "$inlinePath.marks.$m";
                    if (! is_array($mark) || ! in_array($mark['type'] ?? null, ['bold', 'italic', 'underline', 'code', 'highlight', 'link', 'fontFamily'], true)) {
                        $this->fail($markPath, 'Format teks sel tidak didukung.');
                    }
                    if ($mark['type'] === 'link') {
                        if (count($mark) !== 2 || ! is_array($mark['attrs'] ?? null) || count($mark['attrs']) !== 1 || ! is_string($mark['attrs']['href'] ?? null)) {
                            $this->fail($markPath, 'Atribut tautan tidak valid.');
                        }
                        $href = $mark['attrs']['href'];
                        if (! preg_match('#^(https?://|mailto:)#i', $href) || preg_match('/[\x00-\x20]/', $href)) {
                            $this->fail("$markPath.attrs.href", 'Alamat tautan tidak aman.');
                        }
                        $text['marks'][] = ['type' => 'link', 'attrs' => ['href' => $href]];
                    } elseif ($mark['type'] === 'fontFamily') {
                        if (count($mark) !== 2 || ! is_array($mark['attrs'] ?? null) || count($mark['attrs']) !== 1 || ! is_string($mark['attrs']['fontFamily'] ?? null)
                            || ! in_array($mark['attrs']['fontFamily'], self::FONT_FAMILIES, true)) {
                            $this->fail($markPath, 'Jenis font tidak didukung.');
                        }
                        $text['marks'][] = ['type' => 'fontFamily', 'attrs' => ['fontFamily' => $mark['attrs']['fontFamily']]];
                    } else {
                        if (count($mark) !== 1) {
                            $this->fail($markPath, 'Atribut format teks tidak didukung.');
                        }
                        $text['marks'][] = ['type' => $mark['type']];
                    }
                }
            }
            $clean[] = $text;
        }

        return ['type' => 'paragraph', 'content' => $clean];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeImage(array $data, string $path): array
    {
        $imagePath = isset($data['type']) ? $path : "$path.data";
        $url = $data['file']['url'] ?? null;

        if (! is_string($url)
            || strlen($url) > config('documents.editor.max_image_data_length')
            || ! preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/]+={0,2}$#', $url, $matches)) {
            $this->fail("$imagePath.file.url", 'Gambar harus berupa data base64 png, jpeg, atau webp.');
        }
        $bytes = base64_decode(substr($url, strpos($url, ',') + 1), true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($info === false || $info[0] > config('documents.editor.max_image_width') || $info[1] > config('documents.editor.max_image_height')
            || $info['mime'] !== 'image/'.$matches[1]) {
            $this->fail("$imagePath.file.url", 'Gambar tidak valid atau dimensinya melebihi batas.');
        }

        return ['file' => ['url' => $url], 'caption' => $this->text($data['caption'] ?? '', "$imagePath.caption")];
    }

    private function text(mixed $value, string $path): string
    {
        if (! is_string($value) || mb_strlen($value) > config('documents.editor.max_text_length')) {
            $this->fail($path, 'Teks tidak valid atau terlalu panjang.');
        }
        if (preg_match('/<\/?(?:s|del|strike|sub|sup|font)\b/i', $value)) {
            $this->fail($path, 'Format teks ini belum didukung pada PDF.');
        }

        $text = strip_tags($value, self::INLINE_TAGS);
        $text = preg_replace('/<(b|i|u|strong|em|mark|code|br)\b[^>]*>/i', '<$1>', $text) ?? '';
        $text = preg_replace_callback('/<span\b([^>]*)>/i', function (array $match) use ($path): string {
            if (! preg_match('/^\s*data-font-family\s*=\s*(?:"([^"]*)"|\'([^\']*)\')\s*$/i', $match[1], $attribute)) {
                $this->fail($path, 'Jenis font tidak didukung.');
            }
            $fontFamily = $attribute[1] !== '' ? $attribute[1] : $attribute[2];
            if (! in_array($fontFamily, self::FONT_FAMILIES, true)) {
                $this->fail($path, 'Jenis font tidak didukung.');
            }

            return '<span data-font-family="'.e($fontFamily).'">';
        }, $text) ?? '';
        $text = preg_replace('/<\/span\s*>/i', '</span>', $text) ?? '';

        return preg_replace_callback('/<a\b([^>]*)>/i', function (array $match): string {
            if (preg_match('/href\s*=\s*"([^"]*)"/i', $match[1], $href) || preg_match("/href\\s*=\\s*'([^']*)'/i", $match[1], $href)) {
                $url = trim(html_entity_decode($href[1]));
                $url = preg_replace('/[\x00-\x20]+/', '', $url) ?? '';

                if (preg_match('#^(https?://|mailto:)#i', $url)) {
                    return '<a href="'.e($url).'" rel="noopener noreferrer">';
                }
            }

            return '<a>';
        }, $text) ?? '';
    }

    private function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => [$message]]);
    }
}
