<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetSilestariPassword extends Notification
{
    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('password-recovery.url'), '/').route('password.reset', ['token' => $this->token], false).'?'.http_build_query(['email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)->subject('Reset password SILESTARI')->greeting('Halo, '.$notifiable->name)->line('Kami menerima permintaan untuk mengatur ulang password akun SILESTARI Anda.')->action('Buat password baru', $url)->line('Tautan berlaku selama '.config('auth.passwords.users.expire').' menit dan hanya dapat dipakai satu kali.')->line('Jika Anda tidak meminta reset password, abaikan email ini. Password Anda tidak berubah sebelum reset diselesaikan.')->salutation('SILESTARI · PT Sentral Sustainability Consulting');
    }
}
