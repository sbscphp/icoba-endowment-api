<?php

namespace App\Services\Payment;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PaystackCheckoutService
{
    private const API_BASE = 'https://api.paystack.co';

    private string $secret;

    public function __construct(
        private readonly CheckoutRedirectResolver $redirectResolver,
    ) {
        $secret = config('services.paystack.secret');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Paystack is not configured (PAYSTACK_SECRET_KEY).');
        }

        $this->secret = $secret;
    }

    /**
     * Initialize a Paystack transaction and persist its reference on the pending transaction.
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
        $email = $this->resolveDonorEmail($transaction, $donorUser);
        $currency = strtoupper((string) $transaction->currency);
        $reference = $this->buildReference($transaction);

        $redirects = $this->redirectResolver->resolve(
            'paystack',
            $successUrl,
            $failedUrl,
            $legacyCancelUrl,
            $frontendUrl,
        );

        $response = $this->client()->post('/transaction/initialize', [
            'email' => $email,
            'amount' => $this->minorUnitAmount((float) $transaction->amount, $currency),
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $redirects['success_url'],
            'metadata' => [
                'transaction_uuid' => $transaction->uuid,
                'failed_url' => $redirects['failed_url'],
            ],
        ]);

        if (! $response->successful() || ! $response->json('status')) {
            throw new RuntimeException(
                'Paystack initialize failed: '.($response->json('message') ?? $response->body()),
            );
        }

        /** @var array<string, mixed>|null $data */
        $data = $response->json('data');
        if (! is_array($data)) {
            throw new RuntimeException('Paystack initialize returned an invalid response.');
        }

        $authorizationUrl = $data['authorization_url'] ?? null;
        $returnedReference = $data['reference'] ?? $reference;

        if (! is_string($authorizationUrl) || trim($authorizationUrl) === '') {
            throw new RuntimeException('Paystack initialize did not return an authorization URL.');
        }

        if (! is_string($returnedReference) || trim($returnedReference) === '') {
            throw new RuntimeException('Paystack initialize did not return a reference.');
        }

        $transaction->forceFill([
            'gateway' => 'paystack',
            'gateway_reference' => $returnedReference,
        ])->save();

        $this->redirectResolver->persistOnTransaction(
            $transaction,
            $redirects['success_url'],
            $redirects['failed_url'],
        );

        return [
            'session_id' => $returnedReference,
            'url' => $authorizationUrl,
            'success_url' => $redirects['success_url'],
            'failed_url' => $redirects['failed_url'],
        ];
    }

    /**
     * @throws RuntimeException
     */
    public function retrieveCheckoutTransaction(string $reference): PaystackCheckoutTransaction
    {
        $response = $this->client()->get('/transaction/verify/'.rawurlencode($reference));

        if ($response->status() === 404 || ! $response->json('status')) {
            throw new RuntimeException('Invalid Paystack checkout reference.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Unable to verify Paystack checkout reference.');
        }

        /** @var array<string, mixed>|null $data */
        $data = $response->json('data');
        if (! is_array($data)) {
            throw new RuntimeException('Paystack verify returned an invalid response.');
        }

        return PaystackCheckoutTransaction::fromPaystackData($data);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::API_BASE)
            ->acceptJson()
            ->withToken($this->secret)
            ->timeout(30);
    }

    private function resolveDonorEmail(Transaction $transaction, ?User $donorUser): string
    {
        if ($donorUser !== null && is_string($donorUser->email) && trim($donorUser->email) !== '') {
            return $donorUser->email;
        }

        $email = $transaction->donor_email;
        if (! is_string($email) || trim($email) === '') {
            throw new RuntimeException('Guest Paystack Checkout requires donor_email on the transaction.');
        }

        return $email;
    }

    private function buildReference(Transaction $transaction): string
    {
        return 'icoba_'.$transaction->uuid;
    }

    private function minorUnitAmount(float $amount, string $currencyUpper): int
    {
        return (int) round($amount * 100);
    }
}
