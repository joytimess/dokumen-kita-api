<?php

namespace App\Services;

class SupportKnowledgeBase
{
    /** @return list<array{id: string, answer: array{id: string, en: string}, question: array{id: string, en: string}}> */
    public function retrieve(string $message): array
    {
        $message = $this->normalize($message);
        $tokens = $this->tokens($message);
        if ($tokens === []) {
            return [];
        }

        $matches = [];
        foreach (require resource_path('knowledge/support.php') as $entry) {
            $aliases = array_map($this->normalize(...), [...$entry['aliases'], ...array_values($entry['question'])]);
            foreach ($aliases as $alias) {
                // One phrase must cover the intent, including qualifiers and negation.
                if ($alias !== '' && array_diff($tokens, $this->tokens($alias)) === []
                    && str_contains(' '.$message.' ', ' '.$alias.' ')) {
                    $matches[] = array_intersect_key($entry, array_flip(['id', 'answer', 'question']));
                    break;
                }
            }
        }

        return count($matches) === 1 ? $matches : [];
    }

    /** @return list<string> */
    public function suggestions(string $locale): array
    {
        $entries = require resource_path('knowledge/support.php');

        return array_values(array_map(fn (array $entry): string => $entry['question'][$locale], array_filter(
            $entries, fn (array $entry): bool => in_array($entry['id'], ['sign_document', 'import_document', 'signature_assets'], true),
        )));
    }

    public function fallback(string $locale): string
    {
        return $locale === 'en'
            ? 'I can help with supported Dokumen-Kita features and workflows. I do not have enough verified information to answer that question. Please ask a specific question about using the application.'
            : 'Saya dapat membantu penggunaan fitur dan alur Dokumen-Kita yang tersedia. Informasi terverifikasi untuk menjawab pertanyaan tersebut belum memadai. Silakan ajukan pertanyaan spesifik tentang penggunaan aplikasi.';
    }

    public function unavailable(string $locale): string
    {
        return $locale === 'en'
            ? 'The support assistant is temporarily unavailable. Please try again later.'
            : 'Asisten bantuan sementara tidak tersedia. Silakan coba kembali nanti.';
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\bdokumen[\s\p{Z}-]+kita\b/u', ' ', $text);
        // Keep English negation intact when punctuation is removed.
        $text = preg_replace('/\bcan[\x{2019}\x{27}]t\b/u', 'can not', $text);
        $text = preg_replace('/\b(could|do|does|did|is|are|was|were|has|have|had|should|would)n[\x{2019}\x{27}]t\b/u', '$1 not', $text);
        $text = preg_replace('/[^\p{L}\p{M}\p{N}\s\p{Z}]+/u', ' ', $text);
        $text = preg_replace('/[\s\p{Z}]+/u', ' ', $text);
        $text = preg_replace(
            [
                '/\b(?:gimana|gmn|gmna)\b/u',
                '/\b(?:nggak|gak|ngga|ga|enggak)\b/u',
                '/\b(?:ttd|tandatangan)\b/u',
                '/\b(?:liat|lihat)\b/u',
                '/\b(?:nyari|cari)\b/u',
                '/\b(?:hapus|delete)\b/u',
                '/\b(?:download|unduh)\b/u',
                '/\b(?:upload|mengunggah)\b/u',
                '/\bimport\b/u',
                '/\b(?:bikin|buat)\b(?= (?:akun|dokumen|draft|kategori)\b)/u',
                '/\b(?:edit|ubah|ganti)\b(?= (?:dokumen|draft|kategori|nama|email|kata sandi|password|foto|profil)\b)/u',
            ],
            ['bagaimana', 'tidak', 'tanda tangan', 'melihat', 'mencari', 'menghapus', 'mengunduh', 'unggah', 'impor', 'membuat', 'mengubah'],
            $text,
        );

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $stopwords = explode(' ', 'a an the i my me you your we our do does can could would should how what where when why is are to for of in on at with and or about please tell explain use using it this that bagaimana bagaimanakah cara caranya apa apakah di ke dari dan atau untuk dengan tentang pada yang saya aku kami anda kamu gue gua gw ku ingin mau pengen bisa dapat tolong jelaskan menggunakan penggunaan fitur aplikasi ya dong nih sih kok');

        return array_values(array_unique(array_diff($tokens, $stopwords)));
    }
}
