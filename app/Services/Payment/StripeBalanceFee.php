<?php

namespace App\Services\Payment;

/**
 * Turns a Stripe balance transaction into the fee Stripe kept, expressed in the donation currency.
 *
 * Stripe settles into the account currency, so a NGN charge usually produces a USD (or other)
 * balance transaction. When that happens the balance transaction carries `exchange_rate`
 * (presentment -> settlement) and the fee is converted back with it.
 */
final class StripeBalanceFee
{
    /**
     * @param  object|array<string, mixed>|null  $balance  Stripe BalanceTransaction (object or array form)
     * @return array{fee: float|null, settlement_fee: float|null, settlement_currency: string|null, converted: bool}
     */
    public static function inCurrency(object|array|null $balance, string $transactionCurrency): array
    {
        $none = ['fee' => null, 'settlement_fee' => null, 'settlement_currency' => null, 'converted' => false];

        if ($balance === null) {
            return $none;
        }

        $data = is_array($balance) ? $balance : self::toArray($balance);

        $feeMinor = $data['fee'] ?? null;
        $settlementCurrency = isset($data['currency']) ? strtoupper((string) $data['currency']) : null;
        if (! is_numeric($feeMinor) || $settlementCurrency === null || $settlementCurrency === '') {
            return $none;
        }

        $settlementFee = MinorUnits::toMajor((int) $feeMinor, $settlementCurrency);
        $transactionCurrency = strtoupper(trim($transactionCurrency));

        if ($settlementCurrency === $transactionCurrency) {
            return ['fee' => $settlementFee, 'settlement_fee' => $settlementFee, 'settlement_currency' => $settlementCurrency, 'converted' => false];
        }

        $rate = $data['exchange_rate'] ?? null;
        if (is_numeric($rate) && (float) $rate > 0) {
            return [
                'fee' => round(((float) $settlementFee) / (float) $rate, 2),
                'settlement_fee' => $settlementFee,
                'settlement_currency' => $settlementCurrency,
                'converted' => true,
            ];
        }

        return ['fee' => null, 'settlement_fee' => $settlementFee, 'settlement_currency' => $settlementCurrency, 'converted' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(object $balance): array
    {
        if (method_exists($balance, 'toArray')) {
            /** @var array<string, mixed> $array */
            $array = $balance->toArray();

            return $array;
        }

        return get_object_vars($balance);
    }
}
