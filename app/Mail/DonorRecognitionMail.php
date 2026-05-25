<?php

namespace App\Mail;

use App\Models\DonorRecognition;
use App\Models\Theme;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DonorRecognitionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly DonorRecognition $recognition,
        public readonly Transaction $transaction,
        public readonly Theme $mailTheme,
        public readonly string $recipientName,
        public readonly string $tierName,
        public readonly string $certificateDownloadUrl,
        public readonly string $donationReceiptDownloadUrl,
        public readonly string $certificatePdfBinary,
        public readonly string $receiptPdfBinary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Thank you — your '.$this->tierName.' recognition from '.$this->mailTheme->brand_name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.donation.recognition',
            with: [
                'recognition' => $this->recognition,
                'transaction' => $this->transaction,
                'theme' => $this->mailTheme,
                'recipientName' => $this->recipientName,
                'tierName' => $this->tierName,
                'certificateDownloadUrl' => $this->certificateDownloadUrl,
                'donationReceiptDownloadUrl' => $this->donationReceiptDownloadUrl,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->certificatePdfBinary, 'donor-certificate-'.$this->recognition->recognition_number.'.pdf')
                ->withMime('application/pdf'),
            Attachment::fromData(fn () => $this->receiptPdfBinary, 'donation-receipt-'.$this->transaction->transaction_id.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
