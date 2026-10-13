<?php

namespace App\Services\Payment;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * Turns what a gateway reports after payment into finalization context.
 *
 * Webhooks and verify calls only ever write the two gateway_* columns and a mismatch flag.
 * They never touch amount, fee_amount or total_amount: those were fixed at intent time.
 */
final class GatewaySettlementContext
{
    /**
     * @param  float|null  $chargedAtGateway  the figure the gateway says it asked the donor for (should equal total_amount)
     * @param  float|null  $amountReceived  the figure the gateway says it collected (may include the gateway's own donor-borne fee)
     * @param  float|null  $gatewayFee  the fee the gateway says it kept
     * @return array{gateway_amount_received?: float, gateway_fee_amount?: float, metadata: array<string, mixed>}
     */
    public function build(
        Transaction $transaction,
        ?float $chargedAtGateway,
        ?float $amountReceived,
        ?float $gatewayFee,
    ): array {
        $context = ['metadata' => []];

        if ($amountReceived !== null) {
            $context['gateway_amount_received'] = round($amountReceived, 2);
        }
        if ($gatewayFee !== null) {
            $context['gateway_fee_amount'] = round($gatewayFee, 2);
        }

        if ($chargedAtGateway === null) {
            return $context;
        }

        $expected = $transaction->chargeAmount();
        $tolerance = (float) config('platform_charges.gateway_amount_tolerance', 0.01);
        $difference = round($chargedAtGateway - $expected, 2);

        if (abs($difference) > $tolerance) {
            $context['metadata']['gateway_amount_mismatch'] = true;
            $context['metadata']['gateway_charged_amount'] = number_format($chargedAtGateway, 2, '.', '');
            $context['metadata']['gateway_amount_difference'] = number_format($difference, 2, '.', '');

            Log::warning('Gateway charged a different figure than the transaction total.', [
                'transaction_uuid' => $transaction->uuid,
                'gateway' => $transaction->gateway,
                'expected_total' => $expected,
                'gateway_charged' => $chargedAtGateway,
                'difference' => $difference,
            ]);
        }

        return $context;
    }
}
