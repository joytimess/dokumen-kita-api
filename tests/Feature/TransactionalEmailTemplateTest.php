<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\DocumentWorkflowNotification;
use App\Notifications\VerifyEmail;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransactionalEmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{?int, string}> */
    public static function verificationExpirations(): array
    {
        return [
            'existing default' => [null, '1 jam'],
            'one hour' => [60, '1 jam'],
            '24 hours' => [1440, '24 jam'],
            'partial hour' => [90, '90 menit'],
        ];
    }

    #[DataProvider('verificationExpirations')]
    public function test_verification_renders_matching_links_and_the_actual_signed_expiration(?int $minutes, string $notice): void
    {
        $this->freezeTime();
        if ($minutes !== null) {
            config(['auth.verification.expire' => $minutes]);
        }
        $user = User::factory()->unverified()->create();
        $mail = (new VerifyEmail)->toMail($user);
        $html = (string) $mail->render();
        $url = $mail->actionUrl;

        $this->assertSame([$url, $url], $this->links($html));
        $this->assertStringContainsString('/'.$user->guid.'/'.sha1($user->email), $url);
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(now()->addMinutes($minutes ?? 60)->timestamp, (int) $query['expires']);
        $this->assertStringContainsString($notice, strip_tags($html));
        $this->assertStringContainsString('Verifikasi Alamat Email', $html);
        $this->assertStringContainsString('SALAM HANGAT,', $html);
        $this->assertStringContainsString('Tim DokumenKita', $html);

        $this->travel(($minutes ?? 60) * 60 + 1)->seconds();
        $this->getJson($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->travelBack();
        $this->get($url)->assertRedirect(rtrim(config('app.frontend_url'), '/').'/email/verified');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_notification_delivers_html_and_plain_text_through_the_existing_mail_channel(): void
    {
        $user = User::factory()->unverified()->create();
        $messages = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$messages): void {
            $messages[] = $event->message;
        });

        Notification::sendNow($user, new VerifyEmail);

        $this->assertCount(1, $messages);
        $this->assertSame($user->email, $messages[0]->getTo()[0]->getAddress());
        $links = $this->links($messages[0]->getHtmlBody());
        $this->assertCount(2, $links);
        $this->assertSame($links[0], $links[1]);
        $this->assertStringContainsString($links[0], $messages[0]->getTextBody());
        $this->assertStringContainsString('1 jam', $messages[0]->getTextBody());
    }

    /** @return array<string, array{string, string}> */
    public static function invitationTypes(): array
    {
        return [
            'ready' => ['invitation_ready', 'Giliran Anda untuk membaca dan menandatangani dokumen.'],
            'waiting' => ['invitation_waiting', 'giliran Anda belum aktif.'],
        ];
    }

    #[DataProvider('invitationTypes')]
    public function test_invitation_escapes_long_document_names_and_preserves_the_complete_url(string $type, string $description): void
    {
        $title = '<img src=x onerror="alert(1)">'.str_repeat('DokumenPanjang', 100).' & "Perjanjian"';
        $url = 'https://example.test/login?invitation_token='.str_repeat('ab', 32).'&context='.str_repeat('c', 1000).'&label="<tag>"';
        $mail = (new DocumentWorkflowNotification($type, $title, 3, $url))->toMail((object) []);
        $html = (string) $mail->render();

        $this->assertSame([$url, $url], $this->links($html));
        $this->assertStringContainsString(e($title), $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString($description, strip_tags($html));
        $this->assertStringContainsString('NAMA DOKUMEN', $html);
        $this->assertStringContainsString('versi 3', $html);
        $this->assertStringContainsString('Buka undangan', $html);
        $this->assertStringContainsString('word-break: break-all', $html);
        $this->assertStringContainsString('overflow-wrap: anywhere', $html);

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $this->assertStringContainsString($title, $document->textContent);
        $this->assertSame(0, (new DOMXPath($document))->query('//script | //img | //*[@onerror]')->length);
    }

    /** @return list<string> */
    private function links(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $links = [];
        foreach ($document->getElementsByTagName('a') as $anchor) {
            $links[] = $anchor->getAttribute('href');
        }

        return $links;
    }
}
