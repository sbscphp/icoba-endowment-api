<?php

namespace App\Mail;

use App\Enums\OtpPurposeEnum;
use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OTPMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otp,
        public readonly int $expiresInMinutes,
        public readonly string $recipientName,
        public readonly Theme $mailTheme,
        public readonly OtpPurposeEnum $purpose = OtpPurposeEnum::LOGIN,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: match ($this->purpose) {
                OtpPurposeEnum::PASSWORD_RESET => 'Password reset verification code',
                OtpPurposeEnum::LOGIN => 'Sign-in verification code',
                OtpPurposeEnum::EMAIL_VERIFICATION => 'Verify your email address',
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.otp',
            with: [
                'otp' => $this->otp,
                'expiresInMinutes' => $this->expiresInMinutes,
                'recipientName' => $this->recipientName,
                'purpose' => $this->purpose,
                'theme' => $this->mailTheme,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
