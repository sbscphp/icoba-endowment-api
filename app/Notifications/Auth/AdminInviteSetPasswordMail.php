<?php

namespace App\Notifications\Auth;

use App\Services\Theme\ThemeResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminInviteSetPasswordMail extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly string $resetUrl,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $theme = app(ThemeResolver::class)->resolveForMail();
        $expiresInMinutes = (int) config('auth.passwords.admins.expire', 60);

        return (new MailMessage)
            ->subject('Set up your admin account')
            ->view('emails.auth.admin-set-password', [
                'theme' => $theme,
                'admin' => $notifiable,
                'resetUrl' => $this->resetUrl,
                'expiresInMinutes' => $expiresInMinutes,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
