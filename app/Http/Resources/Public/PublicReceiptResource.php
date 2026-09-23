<?php

namespace App\Http\Resources\Public;

use App\Models\Transaction;
use App\Services\Receipt\ReceiptService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Guest-facing view of a receipted transaction, looked up by receipt number.
 *
 * Deliberately omits donor contact details (email, phone) and internal
 * reconciliation fields: the receipt number is shared in emails and on
 * printed receipts, so this payload only carries what the receipt itself shows.
 *
 * @mixin Transaction
 */
class PublicReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Transaction $tx */
        $tx = $this->resource;
        $paidAt = $tx->paid_at ?? $tx->created_at;
        $receiptService = app(ReceiptService::class);

        return [
            'receipt_number' => $tx->receipt_number,
            'transaction_uuid' => $tx->uuid,
            'transaction_id' => $tx->transaction_id,
            'status' => $tx->status instanceof \BackedEnum ? $tx->status->value : $tx->status,
            'transaction_date' => $paidAt?->copy()->utc()->toDateString(),
            'transaction_time' => $paidAt?->copy()->utc()->format('H:i:s\Z'),
            'paid_at' => $tx->paid_at,
            'amount' => (string) $tx->amount,
            'currency' => $tx->currency,
            'amount_in_naira' => $tx->amount_in_naira !== null ? (string) $tx->amount_in_naira : null,
            'exchange_rate_to_naira' => $tx->exchange_rate_to_naira !== null ? (string) $tx->exchange_rate_to_naira : null,
            'is_anonymous' => (bool) $tx->is_anonymous,
            'donor_name' => $this->resolveDonorName($tx),
            'organization_name' => (bool) $tx->is_anonymous ? null : ($tx->organization_name ?? $tx->donor?->organization_name),
            'linked_campaign' => $tx->campaign !== null ? [
                'public_campaign_code' => $tx->campaign->campaign_id,
                'name' => $tx->campaign->name,
            ] : null,
            'payment_method' => $tx->resolvePaymentMethod(),
            'payment_via' => $tx->gateway,
            'receipt_download_url' => $this->downloadUrl($tx, 'download'),
            'tax_receipt_download_url' => $receiptService->isEligibleForTaxReceipt($tx)
                ? $this->downloadUrl($tx, 'tax/download')
                : null,
        ];
    }

    private function resolveDonorName(Transaction $tx): ?string
    {
        if ((bool) $tx->is_anonymous) {
            return 'Anonymous';
        }

        if (filled($tx->donor_name)) {
            return (string) $tx->donor_name;
        }

        if ($tx->donor !== null) {
            $name = trim(implode(' ', array_filter([
                (string) ($tx->donor->firstname ?? ''),
                (string) ($tx->donor->lastname ?? ''),
            ])));
            if ($name !== '') {
                return $name;
            }
        }

        return $tx->organization_name ?? $tx->donor?->organization_name;
    }

    private function downloadUrl(Transaction $tx, string $suffix): string
    {
        $url = rtrim((string) config('app.url'), '/')
            .'/api/v1/public/receipts/'.rawurlencode((string) $tx->receipt_number).'/'.$suffix;

        if (filled($tx->receipt_token)) {
            $url .= '?token='.rawurlencode((string) $tx->receipt_token);
        }

        return $url;
    }
}
