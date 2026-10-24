<?php

namespace App\Services;

class SupportKnowledgeBase
{
    /** @return list<array{id: string, answer: array{id: string, en: string}, question: array{id: string, en: string}}> */
    public function retrieve(string $message): array
    {
        $tokens = $this->tokens($message);
        if ($tokens === []) {
            return [];
        }

        $matches = [];
        foreach (require resource_path('knowledge/support.php') as $entry) {
            $aliases = array_map($this->tokens(...), [...$entry['aliases'], ...array_values($entry['question'])]);
            $vocabulary = array_unique(array_merge(...$aliases));
            // Unknown qualifiers must not turn a partial topic match into product guidance.
            if (array_diff($tokens, $vocabulary) !== []) {
                continue;
            }

            foreach ($aliases as $alias) {
                if ($alias !== [] && array_diff($alias, $tokens) === []) {
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

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/\bdokumen[\s-]+kita\b/u', ' ', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $stopwords = explode(' ', 'a an the i my me do does can could would should how what where when why is are to for of in on at with and or about please tell explain use using it this that bagaimana bagaimanakah cara caranya apa apakah di ke dari dan atau untuk dengan tentang pada yang saya aku kami ingin mau bisa dapat tolong jelaskan menggunakan penggunaan fitur aplikasi ya dong');

        return array_values(array_unique(array_diff($tokens, $stopwords)));
    }
}
