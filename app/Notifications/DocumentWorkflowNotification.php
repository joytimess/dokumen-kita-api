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
        public ?string $completedAt = null,
        public ?int $signedCount = null,
        public ?int $signerCount = null,
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
            'revision_requested' => 'Penandatangan meminta revisi. Penandatanganan dijeda sampai pemilik merevisi dan memublikasikan versi baru.',
            'version_rejected' => 'Penandatangan menolak dokumen. Proses persetujuan versi ini dihentikan.',
            'assignment_signed' => 'Penandatangan telah berhasil menyelesaikan tanda tangan.',
            default => 'Semua persetujuan telah diterima. PDF final sedang diproses.',
        };
        $subject = match ($this->type) {
            'revision_requested' => 'Permintaan Revisi Dokumen',
            'version_rejected' => 'Dokumen Ditolak',
            'assignment_signed' => 'Tanda Tangan Baru',
            'version_completed' => 'Dokumen Selesai Ditandatangani',
            default => null,
        };
        if ($subject !== null) {
            $details = [];
            if ($this->requester !== null) {
                $details[] = 'Penandatangan: '.$this->requester;
            }
            if ($this->reason !== null) {
                $details[] = 'Alasan: '.$this->reason;
            }
            if ($this->signedCount !== null && $this->signerCount !== null) {
                $details[] = 'Progres: '.$this->signedCount.' dari '.$this->signerCount.' penandatangan.';
            }
            if ($this->completedAt !== null) {
                $details[] = 'Waktu selesai: '.$this->completedAt;
            }
            $mail = (new MailMessage)->subject($subject.' — '.$this->title)
                ->greeting('Halo!')->line(e($this->title).' — versi '.$this->versionNumber)->line($description);
            $actionText = match ($this->type) {
                'assignment_signed' => 'Lihat Progres Dokumen',
                'version_completed' => 'Lihat / Unduh Dokumen',
                default => 'Lihat Dokumen',
            };
            if ($this->invitationUrl !== null) {
                $mail->action($actionText, $this->invitationUrl);
            }

            return $mail->view(['html' => 'emails.document-workflow', 'text' => 'emails.text.document-workflow'], [
                'documentTitle' => $this->title,
                'versionNumber' => $this->versionNumber,
                'description' => $description,
                'details' => $details,
            ]);
        }
        $mail = (new MailMessage)->subject('Dokumen Kita — '.$this->title)
            ->greeting('Halo!')->line(e($this->title).' — versi '.$this->versionNumber)->line($description);
        if ($this->invitationUrl !== null) {
            $mail->action('Buka undangan', $this->invitationUrl)
                ->line('Gunakan akun dengan email yang menerima undangan ini dan verifikasi email sebelum melanjutkan.');

            if (in_array($this->type, ['invitation_ready', 'invitation_waiting'], true)) {
                $mail->view(['html' => 'emails.signature-invitation', 'text' => 'emails.text.signature-invitation'], [
                    'documentTitle' => $this->title,
                    'versionNumber' => $this->versionNumber,
                    'description' => $description,
                ]);
            }
        }

        return $mail;
    }
}
