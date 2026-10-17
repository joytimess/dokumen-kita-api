<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailChangeVerification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url)
    {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verifikasi perubahan email')
            ->line('Gunakan tautan berikut untuk memverifikasi alamat email baru Anda.')
            ->action('Verifikasi email', $this->url)
            ->line('Tautan berlaku selama 60 menit dan hanya dapat digunakan sekali.');
    }
}
