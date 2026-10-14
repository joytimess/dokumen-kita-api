<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class PasswordResetOtp extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public string $challengeGuid, public string $otp)
    {
        $this->onConnection('database');
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof User
            && filled($notifiable->password)
            && DB::table('password_reset_challenges')
                ->where('user_guid', $notifiable->guid)
                ->where('guid', $this->challengeGuid)
                ->whereNotNull('otp_hash')
                ->where('failed_attempts', '<', 5)
                ->where('otp_expires_at', '>', now())
                ->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kode OTP reset password Dokumen Kita')
            ->greeting('Halo!')
            ->line('Gunakan kode berikut untuk mereset password Anda:')
            ->line($this->otp)
            ->line('Kode berlaku selama 5 menit sejak permintaan dibuat. Jangan bagikan kode ini kepada siapa pun.')
            ->line('Jika Anda tidak meminta reset password, abaikan email ini.');
    }
}
