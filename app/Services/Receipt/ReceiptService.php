<?php

namespace App\Services\Receipt;

use App\Enums\Currency;
use App\Enums\DonorTypeSlug;
use App\Enums\TransactionStatus;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Models\Transaction;
use App\Models\TransactionReceipt;
use App\Models\User;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Tier\TierResolutionService;

class ReceiptService
{
    public function __construct(
        private readonly TierResolutionService $tierResolution,
        private readonly PledgeBalanceService $balanceService,
    ) {}

    /**
     * Ensure a frozen receipt row exists and return it.
     */
    public function getOrCreateReceiptRecord(Transaction $transaction): TransactionReceipt
    {
        if ($transaction->status !== TransactionStatus::SUCCESSFUL) {
            throw new ApiException('Receipt available only for successful payments.', 422);
        }

        $this->ensurePublicReceiptAccess($transaction);
        $transaction->refresh();

        $existing = $transaction->receipt;
        if ($existing !== null) {
            return $existing;
        }

        $tier = $this->tierResolution->resolveTierForAmount(
            $transaction->amount_in_naira !== null ? (float) $transaction->amount_in_naira : null
        );

        $corporate = $this->corporateDetails($transaction);

        return TransactionReceipt::query()->create([
            'transaction_uuid' => $transaction->uuid,
            'tier_label' => $tier !== null ? $tier->name : TierResolutionService::UNCATEGORIZED_LABEL,
            'tier_uuid' => $tier?->uuid,
            'snapshot' => [
                'amount' => (string) $transaction->amount,
                'currency' => $transaction->currency,
                'amount_in_naira' => $transaction->amount_in_naira !== null ? (string) $transaction->amount_in_naira : null,
                'paid_at' => $transaction->paid_at?->toIso8601String(),
                'transaction_id' => $transaction->transaction_id,
                'organization_name' => $corporate['organization_name'] ?? null,
                'rc_number' => $corporate['rc_number'] ?? null,
                'tin' => $corporate['tin'] ?? null,
            ],
        ]);
    }

    public function isEligibleForTaxReceipt(Transaction $transaction): bool
    {
        if (! $this->isCorporateDonation($transaction)) {
            return false;
        }

        $corporate = $this->corporateDetails($transaction);

        return filled($corporate['rc_number'] ?? null) && filled($corporate['tin'] ?? null);
    }

    public function isCorporateDonation(Transaction $transaction): bool
    {
        $transaction->loadMissing('donorType', 'donor.donorType');

        if ($transaction->donorType?->slug === DonorTypeSlug::CORPORATE_DONOR->value) {
            return true;
        }

        if ($transaction->donor?->donorType?->slug === DonorTypeSlug::CORPORATE_DONOR->value) {
            return true;
        }

        return filled($transaction->organization_name)
            || (filled($transaction->rc_number) && filled($transaction->tin));
    }

    /**
     * @return array{organization_name: ?string, rc_number: ?string, tin: ?string, donor_email: ?string}
     */
    public function corporateDetails(Transaction $transaction): array
    {
        $transaction->loadMissing('donor:uuid,organization_name,rc_number,tin,email,donor_type_uuid', 'donor.donorType');

        $organizationName = trim((string) ($transaction->organization_name ?? ''));
        if ($organizationName === '' && filled($transaction->donor?->organization_name)) {
            $organizationName = trim((string) $transaction->donor->organization_name);
        }
        if ($organizationName === '' && $this->isCorporateDonation($transaction)) {
            $organizationName = trim((string) ($transaction->donor_name ?? ''));
        }

        $rcNumber = trim((string) ($transaction->rc_number ?? ''));
        if ($rcNumber === '' && filled($transaction->donor?->rc_number)) {
            $rcNumber = trim((string) $transaction->donor->rc_number);
        }

        $tin = trim((string) ($transaction->tin ?? ''));
        if ($tin === '' && filled($transaction->donor?->tin)) {
            $tin = trim((string) $transaction->donor->tin);
        }

        return [
            'organization_name' => $organizationName !== '' ? $organizationName : null,
            'rc_number' => $rcNumber !== '' ? $rcNumber : null,
            'tin' => $tin !== '' ? $tin : null,
            'donor_email' => $transaction->donor_email ?? $transaction->donor?->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function donationReceiptViewData(Transaction $transaction): array
    {
        $receipt = $this->getOrCreateReceiptRecord($transaction);
        $transaction->loadMissing('campaign');

        return $this->baseReceiptViewData($transaction, $receipt, 'DONATION RECEIPT');
    }

    /**
     * @return array<string, mixed>
     */
    public function taxReceiptViewData(Transaction $transaction): array
    {
        $receipt = $this->getOrCreateReceiptRecord($transaction);
        $transaction->loadMissing('campaign');

        return $this->baseReceiptViewData($transaction, $receipt, 'TAX RECEIPT');
    }

    public function guestTaxReceiptDownloadUrl(Transaction $transaction): string
    {
        $transaction = $this->ensurePublicReceiptAccess($transaction);

        return rtrim((string) config('app.url'), '/')
            .'/api/v1/public/receipts/'.$transaction->receipt_number.'/tax/download';
    }

    public function guestDonationReceiptDownloadUrl(Transaction $transaction): string
    {
        $transaction = $this->ensurePublicReceiptAccess($transaction);

        return rtrim((string) config('app.url'), '/')
            .'/api/v1/public/receipts/'.$transaction->receipt_number.'/download';
    }

    public function ensurePublicReceiptAccess(Transaction $transaction): Transaction
    {
        $this->balanceService->ensureReceiptToken($transaction);
        $this->ensureReceiptNumber($transaction);
        $transaction->refresh();

        return $transaction;
    }

    public function resolveByReceiptNumber(string $receiptNumber): Transaction
    {
        $receiptNumber = strtoupper(trim($receiptNumber));

        return Transaction::query()
            ->where('receipt_number', $receiptNumber)
            ->firstOrFail();
    }

    public function resolveOwnedTransaction(User $user, string $transactionUuid): Transaction
    {
        return Transaction::query()
            ->where('uuid', $transactionUuid)
            ->where('user_uuid', $user->uuid)
            ->firstOrFail();
    }

    public function receiptNumberFor(Transaction $transaction): string
    {
        if (filled($transaction->receipt_number)) {
            return (string) $transaction->receipt_number;
        }

        $year = ($transaction->paid_at ?? $transaction->created_at ?? now())->format('Y');

        return sprintf('ICOBA-%s-PENDING', $year);
    }

    private function ensureReceiptNumber(Transaction $transaction): void
    {
        if (filled($transaction->receipt_number)) {
            return;
        }

        $transaction->forceFill([
            'receipt_number' => $this->generateUniqueReceiptNumber($transaction),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function receiptPayloadForDownload(Transaction $transaction, bool $maskAnonymousForPublicJson = true): array
    {
        $receipt = $this->getOrCreateReceiptRecord($transaction);
        $tx = $transaction->loadMissing('campaign', 'pledge');
        $corporate = $this->corporateDetails($tx);

        $payload = [
            'receipt_uuid' => $receipt->uuid,
            'transaction_uuid' => $tx->uuid,
            'transaction_id' => $tx->transaction_id,
            'amount' => (string) $tx->amount,
            'currency' => $tx->currency,
            'amount_in_naira' => $tx->amount_in_naira !== null ? (string) $tx->amount_in_naira : null,
            'paid_at' => $tx->paid_at,
            'campaign_name' => $tx->campaign?->name,
            'tier_label' => $receipt->tier_label,
            'pledge_uuid' => $tx->pledge_uuid,
            'is_corporate' => $this->isCorporateDonation($tx),
            'organization_name' => $corporate['organization_name'],
            'rc_number' => $corporate['rc_number'],
            'tin' => $corporate['tin'],
            'tax_receipt_eligible' => $this->isEligibleForTaxReceipt($tx),
        ];

        if ($tx->is_anonymous && $maskAnonymousForPublicJson) {
            $payload['donor_display'] = 'Anonymous';
        } else {
            $payload['donor_name'] = $this->donorDisplayLine($tx) ?? ($tx->donor_name ? (string) $tx->donor_name : null);
        }

        return $payload;
    }

    /**
     * Single display line for PDF/JSON: prefer linked profile (firstname + lastname), else snapshot on the transaction.
     *
     * The anonymous flag intentionally does NOT affect this method. Anonymity only governs how a donor appears
     * on the public leaderboard (handled in LeaderboardService); the donor's own receipt and confirmation emails
     * must still display their real name. Callers that need to mask the name for public-facing surfaces should
     * check `$transaction->is_anonymous` themselves.
     */
    public function donorDisplayLine(Transaction $transaction): ?string
    {
        if ($this->isCorporateDonation($transaction)) {
            $corporate = $this->corporateDetails($transaction);
            if (filled($corporate['organization_name'])) {
                return $corporate['organization_name'];
            }
        }

        $transaction->loadMissing('donor:uuid,firstname,lastname,organization_name');

        if ($transaction->donor !== null && filled($transaction->donor->organization_name)) {
            return trim((string) $transaction->donor->organization_name);
        }

        $fromProfile = trim(implode(' ', array_filter([
            (string) ($transaction->donor?->firstname ?? ''),
            (string) ($transaction->donor?->lastname ?? ''),
        ])));

        if ($fromProfile !== '' && $fromProfile !== 'Organization') {
            return $fromProfile;
        }

        $snap = trim((string) ($transaction->donor_name ?? ''));

        return $snap !== '' ? $snap : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseReceiptViewData(Transaction $transaction, TransactionReceipt $receipt, string $badgeLabel): array
    {
        $corporate = $this->corporateDetails($transaction);
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $issuedAt = $transaction->paid_at ?? now();
        $donationDate = $transaction->paid_at ?? $transaction->created_at ?? now();

        return [
            'badgeLabel' => $badgeLabel,
            'foundationName' => config('endowment.foundation_name'),
            'taxId' => config('endowment.tax_id'),
            'contactEmail' => config('endowment.contact_email'),
            'website' => config('endowment.website'),
            'executiveDirectorName' => config('endowment.executive_director_name'),
            'executiveDirectorTitle' => config('endowment.executive_director_title'),
            'taxStatement' => config('endowment.tax_deductibility_statement'),
            'thankYouMessage' => config('endowment.receipt_thank_you'),
            'logoDataUri' => $this->logoDataUri(),
            'receiptNumber' => $this->receiptNumberFor($transaction),
            'issuedAt' => $issuedAt->format('F j, Y'),
            'issuedAtShort' => $issuedAt->format('M j, Y'),
            'donationDate' => $donationDate->format('F j, Y'),
            'transactionId' => $transaction->transaction_id,
            'amountFormatted' => $this->formatAmount((float) $transaction->amount, (string) $transaction->currency),
            'amount' => (string) $transaction->amount,
            'currency' => (string) $transaction->currency,
            'amountNgnFormatted' => $transaction->amount_in_naira !== null
                ? $this->formatAmount((float) $transaction->amount_in_naira, Currency::NGN->value)
                : null,
            'tierLabel' => $receipt->tier_label,
            'campaignName' => $transaction->campaign?->name ?? 'General Endowment Fund',
            'paymentMethod' => ucwords(str_replace('_', ' ', (string) ($transaction->resolvePaymentMethod() ?? 'credit_card'))),
            'donorName' => $this->donorDisplayLine($transaction),
            'donorEmail' => $corporate['donor_email'],
            'organizationName' => $corporate['organization_name'],
            'rcNumber' => $corporate['rc_number'],
            'tin' => $corporate['tin'],
            'isCorporate' => $this->isCorporateDonation($transaction),
        ];
    }

    private function generateUniqueReceiptNumber(Transaction $transaction): string
    {
        $year = ($transaction->paid_at ?? $transaction->created_at ?? now())->format('Y');
        $prefix = sprintf('ICOBA-%s-', $year);
        $pool = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $suffix = '';
            for ($i = 0; $i < 10; $i++) {
                $suffix .= $pool[random_int(0, strlen($pool) - 1)];
            }

            $receiptNumber = $prefix.$suffix;
            if (! Transaction::query()->where('receipt_number', $receiptNumber)->exists()) {
                return $receiptNumber;
            }
        }

        $generated = GeneralHelper::getModelUniqueRandomId([
            'modelNamespace' => Transaction::class,
            'modelField' => 'receipt_number',
            'prefix' => $prefix,
            'idLength' => 10,
            'idType' => 'numalpha',
        ]);

        if (is_string($generated)) {
            return strtoupper($generated);
        }

        return $prefix.strtoupper(bin2hex(random_bytes(5)));
    }

    private function formatAmount(float $amount, string $currency): string
    {
        $currencyEnum = Currency::tryFrom(strtoupper($currency));
        $symbol = $currencyEnum?->symbol() ?? strtoupper($currency).' ';

        return $symbol.number_format($amount, 2);
    }

    private function logoDataUri(): ?string
    {
        $path = GeneralHelper::resolveMailLogoPath();

        if (! is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
