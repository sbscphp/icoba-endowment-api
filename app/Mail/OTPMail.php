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

    /**
     * Create a new message instance.
     */
    public function __construct(
        public readonly string $otp,
        public readonly int $expiresInMinutes,
        public readonly Theme $mailTheme,
        public readonly OtpPurposeEnum $purpose = OtpPurposeEnum::LOGIN,
    ) {}

    /**
     * Get the message envelope.
     */
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

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        [$heading, $body] = match ($this->purpose) {
            OtpPurposeEnum::PASSWORD_RESET => [
                'Your password reset code',
                "Use the code below to reset your password. This code expires in {$this->expiresInMinutes} minutes.",
            ],
            OtpPurposeEnum::LOGIN => [
                'Your sign-in code',
                "Use the code below to complete your sign in. This code expires in {$this->expiresInMinutes} minutes.",
            ],
            OtpPurposeEnum::EMAIL_VERIFICATION => [
                'Verify your email',
                "Use the code below to verify your email address on the ICOBA Endowment platform. This code expires in {$this->expiresInMinutes} minutes.",
            ],
        };

        return new Content(
            view: 'emails.auth.otp',
            with: [
                'otp' => $this->otp,
                'expiresInMinutes' => $this->expiresInMinutes,
                'theme' => $this->mailTheme,
                'heading' => $heading,
                'body' => $body,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
