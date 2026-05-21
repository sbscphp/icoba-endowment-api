<?php

namespace App\Mail;

use App\Models\Theme;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DonationTaxReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Transaction $transaction,
        public readonly Theme $mailTheme,
        public readonly string $recipientName,
        public readonly string $taxReceiptDownloadUrl,
        public readonly string $donationReceiptDownloadUrl,
        public readonly string $pdfBinary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your tax receipt from '.$this->mailTheme->brand_name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.donation.tax-receipt',
            with: [
                'transaction' => $this->transaction,
                'theme' => $this->mailTheme,
                'recipientName' => $this->recipientName,
                'taxReceiptDownloadUrl' => $this->taxReceiptDownloadUrl,
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
            Attachment::fromData(fn () => $this->pdfBinary, 'tax-receipt-'.$this->transaction->transaction_id.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
