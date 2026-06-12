<?php

namespace App\Services\PublicDownload;

use App\Enums\IssuedCertificateStatus;
use App\Enums\PublicDocumentType;
use App\Exceptions\ApiException;
use App\Models\DonorRecognition;
use App\Models\PublicDocumentDownloadToken;
use App\Models\Transaction;
use App\Services\Receipt\ReceiptPdfService;
use App\Services\Receipt\ReceiptService;
use App\Services\Recognition\CertificatePdfService;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class PublicDocumentDownloadService
{
    public function __construct(
        private readonly CertificatePdfService $certificatePdfService,
        private readonly ReceiptPdfService $receiptPdfService,
        private readonly ReceiptService $receiptService,
    ) {}

    public function issueRecognitionCertificateToken(DonorRecognition $recognition): PublicDocumentDownloadToken
    {
        return $this->issueToken(PublicDocumentType::RecognitionCertificate, $recognition->uuid);
    }

    public function issueDonationReceiptToken(Transaction $transaction): PublicDocumentDownloadToken
    {
        $this->receiptService->ensurePublicReceiptAccess($transaction);

        return $this->issueToken(PublicDocumentType::DonationReceipt, $transaction->uuid);
    }

    public function issueTaxReceiptToken(Transaction $transaction): PublicDocumentDownloadToken
    {
        $this->receiptService->ensurePublicReceiptAccess($transaction);

        return $this->issueToken(PublicDocumentType::TaxReceipt, $transaction->uuid);
    }

    public function publicDownloadUrl(PublicDocumentDownloadToken $tokenRecord): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .'/download/'.urlencode($tokenRecord->token);
    }

    public function apiDownloadUrl(PublicDocumentDownloadToken $tokenRecord): string
    {
        return rtrim((string) config('app.url'), '/')
            .'/api/v1/public/downloads/'.urlencode($tokenRecord->token);
    }

    public function revokeTokensForRecognition(DonorRecognition $recognition): void
    {
        PublicDocumentDownloadToken::query()
            ->where('subject_uuid', $recognition->uuid)
            ->where('document_type', PublicDocumentType::RecognitionCertificate)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function streamByToken(string $token): Response
    {
        $tokenRecord = $this->resolveActiveToken($token);

        return match ($tokenRecord->document_type) {
            PublicDocumentType::RecognitionCertificate => $this->streamRecognitionCertificate($tokenRecord),
            PublicDocumentType::DonationReceipt => $this->streamDonationReceipt($tokenRecord),
            PublicDocumentType::TaxReceipt => $this->streamTaxReceipt($tokenRecord),
        };
    }

    private function issueToken(PublicDocumentType $documentType, string $subjectUuid): PublicDocumentDownloadToken
    {
        $existing = PublicDocumentDownloadToken::query()
            ->where('document_type', $documentType)
            ->where('subject_uuid', $subjectUuid)
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($existing instanceof PublicDocumentDownloadToken) {
            return $existing;
        }

        return PublicDocumentDownloadToken::query()->create([
            'token' => Str::random(48),
            'document_type' => $documentType,
            'subject_uuid' => $subjectUuid,
        ]);
    }

    private function resolveActiveToken(string $token): PublicDocumentDownloadToken
    {
        $token = trim($token);
        if ($token === '') {
            throw new ApiException(
                'This download link is invalid or has expired.',
                404,
                ['code' => 'document_not_found'],
            );
        }

        $tokenRecord = PublicDocumentDownloadToken::query()
            ->where('token', $token)
            ->first();

        if ($tokenRecord === null) {
            throw new ApiException(
                'This download link is invalid or has expired.',
                404,
                ['code' => 'document_not_found'],
            );
        }

        if ($tokenRecord->isRevoked()) {
            throw new ApiException(
                $this->revokedMessage($tokenRecord->document_type),
                403,
                ['code' => 'document_revoked'],
            );
        }

        if ($tokenRecord->isExpired()) {
            throw new ApiException(
                'This download link has expired.',
                410,
                ['code' => 'document_expired'],
            );
        }

        return $tokenRecord;
    }

    private function streamRecognitionCertificate(PublicDocumentDownloadToken $tokenRecord): Response
    {
        $recognition = DonorRecognition::query()
            ->where('uuid', $tokenRecord->subject_uuid)
            ->first();

        if ($recognition === null) {
            throw new ApiException(
                'This download link is invalid or has expired.',
                404,
                ['code' => 'document_not_found'],
            );
        }

        if ($recognition->status === IssuedCertificateStatus::REVOKED) {
            throw new ApiException(
                'This certificate has been revoked and is no longer available for download.',
                403,
                ['code' => 'document_revoked'],
            );
        }

        return $this->certificatePdfService->streamCertificate($recognition);
    }

    private function streamDonationReceipt(PublicDocumentDownloadToken $tokenRecord): Response
    {
        $transaction = $this->resolveTransaction($tokenRecord->subject_uuid);

        return $this->receiptPdfService->streamDonationReceipt($transaction);
    }

    private function streamTaxReceipt(PublicDocumentDownloadToken $tokenRecord): Response
    {
        $transaction = $this->resolveTransaction($tokenRecord->subject_uuid);

        return $this->receiptPdfService->streamTaxReceipt($transaction);
    }

    private function resolveTransaction(string $transactionUuid): Transaction
    {
        $transaction = Transaction::query()
            ->where('uuid', $transactionUuid)
            ->first();

        if ($transaction === null) {
            throw new ApiException(
                'This download link is invalid or has expired.',
                404,
                ['code' => 'document_not_found'],
            );
        }

        return $transaction;
    }

    private function revokedMessage(PublicDocumentType $documentType): string
    {
        return match ($documentType) {
            PublicDocumentType::RecognitionCertificate => 'This certificate has been revoked and is no longer available for download.',
            PublicDocumentType::DonationReceipt, PublicDocumentType::TaxReceipt => 'This receipt is no longer available for download.',
        };
    }
}
