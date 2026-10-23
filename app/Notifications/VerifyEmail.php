<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmail extends BaseVerifyEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->afterCommit();
    }

    protected function buildMailMessage($url): MailMessage
    {
        $minutes = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Verifikasi Alamat Email — DokumenKita')
            ->action('Verifikasi Alamat Email', $url)
            ->view(['html' => 'emails.verification', 'text' => 'emails.text.verification'], [
                'expiresIn' => $minutes % 60 === 0 ? intdiv($minutes, 60).' jam' : $minutes.' menit',
            ]);
    }
}
