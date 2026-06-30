<?php

namespace App\Services\Payment;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FcmbCheckoutService
{
    private string $businessId;

    private string $secretKey;

    private string $baseUrl;

    public function __construct(
        private readonly CheckoutRedirectResolver $redirectResolver,
    ) {
        $businessId = config('services.fcmb.business_id');
        $secretKey = config('services.fcmb.secret_key');
        $baseUrl = config('services.fcmb.base_url');

        if (! is_string($businessId) || $businessId === '') {
            throw new RuntimeException('FCMB CLNX is not configured (FCMB_CLNX_BUSINESS_ID).');
        }

        if (! is_string($secretKey) || $secretKey === '') {
            throw new RuntimeException('FCMB CLNX is not configured (FCMB_CLNX_SECRET_KEY).');
        }

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new RuntimeException('FCMB CLNX is not configured (FCMB_CLNX_BASE_URL).');
        }

        $this->businessId = $businessId;
        $this->secretKey = $secretKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Initialize a CLNX hosted checkout session and persist its reference on the pending transaction.
     *
     * @return array{session_id: string, url: string, success_url: string, failed_url: string}
     */
    public function createCheckoutSession(
        Transaction $transaction,
        ?User $donorUser,
        ?string $successUrl,
        ?string $failedUrl,
        ?string $legacyCancelUrl = null,
        ?string $frontendUrl = null,
    ): array {
        $currency = strtoupper((string) $transaction->currency);
        $this->assertCurrencyAllowed($currency);

        $email = $this->resolveDonorEmail($transaction, $donorUser);
        $invoiceRequestReference = (string) $transaction->uuid;
        $amount = (float) $transaction->amount;
        [$firstName, $lastName] = $this->splitDonorName($transaction);

        $redirects = $this->redirectResolver->resolve(
            'fcmb',
            $successUrl,
            $failedUrl,
            $legacyCancelUrl,
            $frontendUrl,
        );

        $payload = [
            'amount' => $amount,
            'description' => 'ICOBA Endowment Donation',
            'currency' => $currency,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'invoiceRequestReference' => $invoiceRequestReference,
            'isVatEnabled' => false,
            'customFields' => [
                [
                    'label' => 'transaction_uuid',
                    'value' => $transaction->uuid,
                ],
            ],
            'hash' => $this->hashInitializePayment($amount, $email, $invoiceRequestReference),
        ];

        $settlements = $this->buildSettlements($currency);
        if ($settlements !== null) {
            $payload['settlements'] = $settlements;
        }

        $phone = $this->resolveDonorPhone($transaction, $donorUser);
        if ($phone !== null) {
            $payload['phoneNumber'] = $phone;
        }

        $response = $this->client()->post('/api/v1/public/pay', $payload);

        if (! $response->successful() || ! $response->json('status')) {
            throw new RuntimeException(
                'FCMB checkout initialize failed: '.($response->json('message') ?? $response->body()),
            );
        }

        /** @var array<string, mixed>|null $data */
        $data = $response->json('data');
        if (! is_array($data)) {
            throw new RuntimeException('FCMB checkout initialize returned an invalid response.');
        }

        $paymentUrl = $data['paymentUrl'] ?? null;
        if (! is_string($paymentUrl) || trim($paymentUrl) === '') {
            throw new RuntimeException('FCMB checkout initialize did not return a payment URL.');
        }

        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $metadata['payment_method'] = 'fcmb_checkout';

        $transaction->forceFill([
            'gateway' => 'fcmb',
            'gateway_reference' => $invoiceRequestReference,
            'metadata' => $metadata,
        ])->save();

        $this->redirectResolver->persistOnTransaction(
            $transaction,
            $redirects['success_url'],
            $redirects['failed_url'],
        );

        return [
            'session_id' => $invoiceRequestReference,
            'url' => $paymentUrl,
            'success_url' => $redirects['success_url'],
            'failed_url' => $redirects['failed_url'],
        ];
    }

    /**
     * @throws RuntimeException
     */
    public function retrieveCheckoutTransaction(string $invoiceRequestReference): FcmbCheckoutTransaction
    {
        $hash = $this->hashStatusLookup($invoiceRequestReference);
        $response = $this->client()->get(
            '/api/v1/public/pay/'.rawurlencode($invoiceRequestReference),
            ['hash' => $hash],
        );

        if ($response->status() === 404 || ! $response->json('status')) {
            throw new RuntimeException('Invalid FCMB checkout reference.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Unable to verify FCMB checkout reference.');
        }

        /** @var array<string, mixed>|null $data */
        $data = $response->json('data');
        if (! is_array($data)) {
            throw new RuntimeException('FCMB checkout verify returned an invalid response.');
        }

        return FcmbCheckoutTransaction::fromClnxData($data);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verifyWebhookHash(array $payload): bool
    {
        $received = $payload['hash'] ?? null;
        if (! is_string($received) || $received === '') {
            return false;
        }

        $amount = $payload['amount'] ?? null;
        $reference = $payload['reference'] ?? null;
        $invoiceRequestReference = $payload['invoiceRequestReference'] ?? null;
        $transactionDate = $payload['transactionDate'] ?? null;

        if ($amount === null || ! is_string($reference) || $reference === ''
            || ! is_string($invoiceRequestReference) || $invoiceRequestReference === ''
            || ! is_string($transactionDate) || $transactionDate === '') {
            return false;
        }

        $plain = implode('|', [
            $this->hashableScalar($amount),
            $reference,
            $invoiceRequestReference,
            $transactionDate,
            $this->secretKey,
        ]);

        return hash_equals(hash('sha512', $plain), $received);
    }

    private function hashInitializePayment(float $amount, string $email, string $invoiceRequestReference): string
    {
        $plain = implode('|', [
            $this->hashableScalar($amount),
            $email,
            $invoiceRequestReference,
            $this->secretKey,
        ]);

        return hash('sha512', $plain);
    }

    private function hashStatusLookup(string $invoiceRequestReference): string
    {
        return hash('sha512', $invoiceRequestReference.'|'.$this->secretKey);
    }

    /**
     * @return list<array{accountNumber: string, type: string, category: string, value: int}>|null
     */
    private function buildSettlements(string $currency): ?array
    {
        $accounts = config('services.fcmb.settlement_accounts', []);
        if (! is_array($accounts)) {
            return null;
        }

        $accountNumber = $accounts[strtoupper($currency)] ?? null;
        if (! is_string($accountNumber) || trim($accountNumber) === '') {
            return null;
        }

        return [[
            'accountNumber' => trim($accountNumber),
            'type' => 'PERCENTAGE',
            'category' => 'SINGLE',
            'value' => 100,
        ]];
    }

    private function assertCurrencyAllowed(string $currency): void
    {
        $allowed = config('services.fcmb.allowed_currencies', ['NGN']);
        if (! is_array($allowed)) {
            $allowed = ['NGN'];
        }

        if (! in_array($currency, $allowed, true)) {
            throw new RuntimeException(
                'FCMB checkout is not available for '.$currency.' donations at this time.',
            );
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withHeaders(['x-business-id' => $this->businessId])
            ->timeout(30);
    }

    private function resolveDonorEmail(Transaction $transaction, ?User $donorUser): string
    {
        if ($donorUser !== null && is_string($donorUser->email) && trim($donorUser->email) !== '') {
            return $donorUser->email;
        }

        $email = $transaction->donor_email;
        if (! is_string($email) || trim($email) === '') {
            throw new RuntimeException('Guest FCMB checkout requires donor_email on the transaction.');
        }

        return $email;
    }

    private function resolveDonorPhone(Transaction $transaction, ?User $donorUser): ?string
    {
        $phone = $transaction->donor_phone;
        if (is_string($phone) && trim($phone) !== '') {
            return trim($phone);
        }

        if ($donorUser !== null && is_string($donorUser->phone) && trim($donorUser->phone) !== '') {
            return trim($donorUser->phone);
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitDonorName(Transaction $transaction): array
    {
        $name = trim((string) ($transaction->donor_name ?? ''));
        if ($name === '') {
            return ['Donor', 'Guest'];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [];

        return [
            $parts[0] ?? 'Donor',
            $parts[1] ?? 'Guest',
        ];
    }

    private function hashableScalar(float|int|string $value): string
    {
        if (is_float($value) || is_int($value)) {
            $formatted = rtrim(rtrim(sprintf('%.4F', (float) $value), '0'), '.');

            return $formatted === '' ? '0' : $formatted;
        }

        return (string) $value;
    }
}
