<?php

namespace App\Services;

class SupportKnowledgeBase
{
    public function answer(array $entry, string $message, string $locale): string
    {
        if (preg_match('/\b(detail|details|rinci|terperinci|lengkap|mendalam|in depth|step by step|langkah demi langkah)\b/iu', $message)) {
            return $entry['answer'][$locale];
        }
        $followUp = $this->emailFollowUp($message, $entry['id']);
        if ($followUp !== null) {
            return $followUp['answer'][$locale];
        }
        $facets = match ($entry['id']) {
            'login' => ['/\b(logout|log out|keluar|mengakhiri sesi)\b/iu' => [3], '/\b(salah|incorrect|tidak bisa|can not)\b/iu' => [2]],
            'email_verification' => ['/\b(ulang|resend|another|belum|tidak|not)\b/iu' => [1]],
            'import_document' => ['/\b(tata letak|layout)\b/iu' => [4], '/\b(konfirmasi|confirmation|replac|penggantian)/iu' => [2]],
            'editor_autosave' => ['/\b(konflik|conflict|sesi lain|another session)\b/iu' => [1]],
            'publish_invitation' => ['/\b(ulang|resend)\b/iu' => [2]],
            'claim_invitation' => ['/\b(kedaluwarsa|expired|dicabut|revoked)\b/iu' => [2]],
            'sign_document' => ['/\b(konfirmasi baca|confirm reading|baca semua halaman|read all pages)\b/iu' => [1]],
            'signature_assets' => ['/\b(hapus|menghapus|delete|deleted)\b/iu' => [3], '/\b(kosong|blank|ditolak|rejected)\b/iu' => [1]],
            'signing_history' => ['/\b(snapshot)\b/iu' => [3]],
            'profile' => ['/\b(google|password|kata sandi)\b/iu' => [3], '/\b(email)\b/iu' => [2, 3], '/\b(foto|photo|picture)\b/iu' => [0, 1]],
            default => [],
        };
        foreach ($facets as $pattern => $indices) {
            if (preg_match($pattern, $message)) {
                $sentences = preg_split('/(?<=[.!?])\s+/u', $entry['answer'][$locale]);

                return implode(' ', array_map(fn ($index) => $sentences[$index], $indices));
            }
        }
        $entries = array_column(require resource_path('knowledge/support.php'), null, 'id');

        return $entries[$entry['id']]['concise_answer'][$locale];
    }

    public function emailFollowUp(string $message, ?string $topic): ?array
    {
        $message = $this->normalize($message);
        $missing = preg_match('/\A(?:(?:kalau|jika|kok) )?(?:email(?:nya)?|undangan(?:nya)?|email undangan) (?:belum|tidak) (?:masuk|sampai|diterima)\z/u', $message)
            || preg_match('/\A(?:(?:what if|if) )?(?:(?:the|my) )?(?:email|invitation email|invitation) (?:has not arrived|hasn t arrived|did not arrive|is not here)\z/u', $message);
        $receiving = preg_match('/\A(?:apakah )?(?:nanti )?saya (?:akan )?(?:mendapatkan|menerima|dapat) email(?: juga)?\z/u', $message)
            || preg_match('/\A(?:will i (?:receive|get) (?:an? )?email(?: then| too)?|apa perlu email juga)\z/u', $message);
        if (! $missing && ! $receiving) {
            return null;
        }
        $topic = match ($topic) {
            'sign_document', 'claim_invitation', 'signing_order', 'publish_invitation' => 'publish_invitation',
            'registration', 'email_verification' => 'registration',
            default => null,
        };
        if ($topic === null) {
            return null;
        }
        $entries = array_column(require resource_path('knowledge/support.php'), null, 'id');
        $entry = $entries[$topic];
        $entry['answer'] = $entry[$missing ? 'missing_email_answer' : 'email_answer'];

        return $entry;
    }

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
