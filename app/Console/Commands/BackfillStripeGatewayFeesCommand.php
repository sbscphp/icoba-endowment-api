<?php

namespace App\Console\Commands;

use App\Enums\PaymentGateway;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Payment\StripeCheckoutService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class BackfillStripeGatewayFeesCommand extends Command
{
    protected $signature = 'donations:backfill-stripe-fees
                            {--transaction=* : Transaction UUID or TRN- id (repeatable); default is every successful Stripe row without a gateway fee}
                            {--limit=200 : Max transactions to process}
                            {--force : Write the change (without it the command only previews)}';

    protected $description = 'Fetch the fee Stripe kept on successful Stripe payments that have no gateway_fee_amount yet. Never touches amount, fee_amount or total_amount.';

    // php artisan donations:backfill-stripe-fees
    // php artisan donations:backfill-stripe-fees --force
    // php artisan donations:backfill-stripe-fees --transaction=TRN-2K4mhwTvc3Sl --force

    public function handle(): int
    {
        try {
            $stripe = app(StripeCheckoutService::class);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = ! (bool) $this->option('force');
        if ($dryRun) {
            $this->info('Dry run — no records will be updated.');
        }

        $query = Transaction::query()
            ->where('gateway', PaymentGateway::Stripe->value)
            ->where('status', TransactionStatus::SUCCESSFUL)
            ->whereNull('gateway_fee_amount')
            ->orderBy('paid_at');

        $ids = array_map('strval', (array) $this->option('transaction'));
        if ($ids !== []) {
            $query->where(function (Builder $builder) use ($ids): void {
                $builder->whereIn('uuid', $ids)->orWhereIn('transaction_id', $ids);
            });
        }

        $rows = [];
        $updated = 0;

        foreach ($query->limit(max(1, (int) $this->option('limit')))->get() as $transaction) {
            /** @var Transaction $transaction */
            $paymentIntentId = $this->paymentIntentIdFor($transaction);
            if ($paymentIntentId === null) {
                $rows[] = $this->row($transaction, null, 'no payment intent reference');

                continue;
            }

            $result = $stripe->resolveGatewayFee($paymentIntentId, (string) $transaction->currency);
            if ($result['fee'] === null) {
                $note = $result['settlement_fee'] !== null
                    ? sprintf('fee %s %s but no exchange rate to convert', $result['settlement_fee'], $result['settlement_currency'])
                    : 'fee not available from Stripe';
                $rows[] = $this->row($transaction, null, $note);

                continue;
            }

            $rows[] = $this->row($transaction, $result['fee'], $result['converted'] ? 'converted from '.$result['settlement_currency'] : 'same currency');

            if ($dryRun) {
                continue;
            }

            $meta = is_array($transaction->metadata) ? $transaction->metadata : [];
            if ($result['settlement_fee'] !== null) {
                $meta['stripe_balance_fee'] = number_format($result['settlement_fee'], 2, '.', '');
                $meta['stripe_balance_fee_currency'] = $result['settlement_currency'];
                $meta['stripe_fee_converted'] = $result['converted'];
            }
            $meta['stripe_fee_backfilled_at'] = now()->toIso8601String();

            $transaction->forceFill([
                'gateway_fee_amount' => $result['fee'],
                'metadata' => $meta,
            ])->save();
            $updated++;
        }

        if ($rows !== []) {
            $this->table(array_keys($rows[0]), $rows);
        } else {
            $this->info('Nothing to backfill.');
        }

        $this->info($dryRun ? count($rows).' transaction(s) previewed.' : $updated.' transaction(s) updated.');

        return self::SUCCESS;
    }

    private function paymentIntentIdFor(Transaction $transaction): ?string
    {
        $meta = is_array($transaction->metadata) ? $transaction->metadata : [];
        $intent = $meta['stripe_payment_intent_id'] ?? null;
        if (is_string($intent) && str_starts_with($intent, 'pi_')) {
            return $intent;
        }

        $reference = $transaction->gateway_reference;

        return is_string($reference) && str_starts_with($reference, 'pi_') ? $reference : null;
    }

    /**
     * @return array<string, string>
     */
    private function row(Transaction $transaction, ?float $fee, string $note): array
    {
        return [
            'transaction' => (string) $transaction->transaction_id,
            'currency' => (string) $transaction->currency,
            'service_fee' => (string) ($transaction->fee_amount ?? '0.00'),
            'stripe_fee' => $fee !== null ? number_format($fee, 2, '.', '') : '-',
            'margin' => $fee !== null ? number_format((float) ($transaction->fee_amount ?? 0) - $fee, 2, '.', '') : '-',
            'note' => $note,
        ];
    }
}
