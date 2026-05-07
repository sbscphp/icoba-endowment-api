<?php

namespace App\Notifications\Admin;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InitialPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $temporaryPassword,
        private readonly string $loginUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Admin Account Credentials')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('An admin account has been created for you.')
            ->line('Temporary password: '.$this->temporaryPassword)
            ->line('For security, you must change this password immediately after your first login.')
            ->action('Login', $this->loginUrl)
            ->line('If you did not expect this account, please contact support.');
    }
}
