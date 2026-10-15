<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Delivered synchronously inside DeliverDocumentEmail, which owns retries and state. */
class DocumentWorkflowNotification extends Notification
{
    public function __construct(
        public string $type,
        public string $title,
        public int $versionNumber,
        public ?string $invitationUrl,
        public ?string $reason = null,
        public ?string $requester = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $description = match ($this->type) {
            'invitation_ready' => 'Giliran Anda untuk membaca dan menandatangani dokumen.',
            'invitation_waiting' => 'Anda diundang sebagai penandatangan. Silakan klaim undangan; giliran Anda belum aktif.',
            'version_completed' => 'Semua persetujuan selesai dan PDF final sudah tersedia.',
            'revision_requested' => 'Penandatangan meminta revisi. Versi ini dihentikan dan draft baru sudah dibuat.',
            default => 'Semua persetujuan telah diterima. PDF final sedang diproses.',
        };
        $mail = (new MailMessage)->subject('Dokumen Kita — '.$this->title)
            ->greeting('Halo!')->line(e($this->title).' — versi '.$this->versionNumber)->line($description);
        if ($this->invitationUrl !== null) {
            $mail->action('Buka undangan', $this->invitationUrl)
                ->line('Gunakan akun dengan email yang menerima undangan ini dan verifikasi email sebelum melanjutkan.');
        }

        if ($this->type === 'revision_requested' && $this->reason !== null) {
            if ($this->requester !== null) {
                $mail->line('Peminta: '.e($this->requester));
            }
            $mail->line('Alasan: '.e($this->reason));
        }

        return $mail;
    }
}
