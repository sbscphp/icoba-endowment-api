<?php

namespace Tests\Unit\Services\Payment;

use App\Models\Transaction;
use App\Services\Payment\CheckoutRedirectResolver;
use App\Services\Payment\FcmbCheckoutService;
use Illuminate\Support\Str;
use Tests\TestCase;

class FcmbCheckoutServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fcmb.base_url' => 'https://dev.clnx.io',
            'services.fcmb.business_id' => 'test-business-id',
            'services.fcmb.secret_key' => 'test-secret-key',
        ]);
    }

    public function test_verify_webhook_hash_accepts_valid_payload(): void
    {
        $payload = [
            'amount' => 109.5,
            'reference' => 'WTU-c47c8029-59c5-47f8-9c76-c3fa31f2c846',
            'invoiceRequestReference' => '1905d354-18ae-428c-849b-ef4d3d93e297',
            'transactionDate' => '2025-12-29T03:57:26',
        ];

        $plain = implode('|', [
            '109.5',
            $payload['reference'],
            $payload['invoiceRequestReference'],
            $payload['transactionDate'],
            'test-secret-key',
        ]);
        $payload['hash'] = hash('sha512', $plain);

        $service = $this->makeService();

        $this->assertTrue($service->verifyWebhookHash($payload));
    }

    public function test_verify_webhook_hash_accepts_clnx_fixed_scale_amount_from_raw_body(): void
    {
        // CLNX serialises amount as 109.5000 and hashes that literal; json_decode collapses it to 109.5.
        $rawBody = '{"reference":"WTU-c47c8029-59c5-47f8-9c76-c3fa31f2c846","amount":109.5000,"originalAmount":100,'
            .'"invoiceRequestReference":"1905d354-18ae-428c-849b-ef4d3d93e297","transactionDate":"2025-12-29T03:57:26","hash":"%s"}';

        $hash = hash('sha512', implode('|', [
            '109.5000',
            'WTU-c47c8029-59c5-47f8-9c76-c3fa31f2c846',
            '1905d354-18ae-428c-849b-ef4d3d93e297',
            '2025-12-29T03:57:26',
            'test-secret-key',
        ]));
        $rawBody = sprintf($rawBody, $hash);
        $payload = json_decode($rawBody, true);

        $this->assertSame(109.5, $payload['amount']);
        $this->assertTrue($this->makeService()->verifyWebhookHash($payload, $rawBody));
    }

    public function test_verify_webhook_hash_accepts_whole_amount_hashed_with_four_decimals_without_raw_body(): void
    {
        $payload = [
            'amount' => 100,
            'reference' => 'GAT-116e0e07-7572-47fe-81fd-22fb46f2e7f3',
            'invoiceRequestReference' => 'd6a44168-cf12-4e3f-9b65-04895667a398',
            'transactionDate' => '2025-12-29T07:20:24',
        ];
        $payload['hash'] = hash('sha512', implode('|', [
            '100.0000',
            $payload['reference'],
            $payload['invoiceRequestReference'],
            $payload['transactionDate'],
            'test-secret-key',
        ]));

        $this->assertTrue($this->makeService()->verifyWebhookHash($payload));
    }

    public function test_verify_webhook_hash_ignores_original_amount_when_reading_raw_body(): void
    {
        $rawBody = '{"originalAmount":100,"amount":107.5000,"reference":"GAT-1","invoiceRequestReference":"inv-1","transactionDate":"2025-12-29T07:20:24","hash":"%s"}';
        $hash = hash('sha512', implode('|', ['107.5000', 'GAT-1', 'inv-1', '2025-12-29T07:20:24', 'test-secret-key']));
        $rawBody = sprintf($rawBody, $hash);

        $this->assertTrue($this->makeService()->verifyWebhookHash(json_decode($rawBody, true), $rawBody));
    }

    public function test_verify_webhook_hash_rejects_tampered_payload(): void
    {
        $payload = [
            'amount' => 100,
            'reference' => 'WTU-test',
            'invoiceRequestReference' => '1905d354-18ae-428c-849b-ef4d3d93e297',
            'transactionDate' => '2025-12-29T03:57:26',
            'hash' => hash('sha512', 'invalid'),
        ];

        $this->assertFalse($this->makeService()->verifyWebhookHash($payload));
    }

    public function test_initialize_payload_includes_return_url_with_gateway_and_reference(): void
    {
        $transaction = $this->makeTransaction();

        $payload = $this->makeService()->buildInitializePayload(
            $transaction,
            null,
            'https://frontend.example.com/donate/success',
        );

        $this->assertSame(
            'https://frontend.example.com/donate/success?payment_gateway=fcmb&reference='.$transaction->uuid,
            $payload['returnUrl'],
        );
        $this->assertSame($transaction->uuid, $payload['invoiceRequestReference']);
        $this->assertSame([['label' => 'transaction_uuid', 'value' => $transaction->uuid]], $payload['customFields']);
        $this->assertSame('NGN', $payload['currency']);
        $this->assertSame('Ada', $payload['firstName']);
        $this->assertSame('Lovelace', $payload['lastName']);
        $this->assertSame('donor@example.com', $payload['email']);
    }

    public function test_initialize_payload_appends_return_query_to_existing_query_string(): void
    {
        $transaction = $this->makeTransaction();

        $payload = $this->makeService()->buildInitializePayload(
            $transaction,
            null,
            'https://frontend.example.com/donate/success?campaign=alumni',
        );

        $this->assertSame(
            'https://frontend.example.com/donate/success?campaign=alumni&payment_gateway=fcmb&reference='.$transaction->uuid,
            $payload['returnUrl'],
        );
    }

    public function test_initialize_payload_hash_excludes_return_url(): void
    {
        $transaction = $this->makeTransaction();

        $payload = $this->makeService()->buildInitializePayload(
            $transaction,
            null,
            'https://frontend.example.com/donate/success',
        );

        $expected = hash('sha512', implode('|', [
            '5000',
            'donor@example.com',
            $transaction->uuid,
            'test-secret-key',
        ]));

        $this->assertSame($expected, $payload['hash']);
    }

    private function makeTransaction(): Transaction
    {
        $transaction = new Transaction([
            'amount' => 5000,
            'currency' => 'NGN',
            'donor_email' => 'donor@example.com',
            'donor_name' => 'Ada Lovelace',
        ]);
        $transaction->uuid = (string) Str::uuid();

        return $transaction;
    }

    private function makeService(): FcmbCheckoutService
    {
        return new FcmbCheckoutService(new CheckoutRedirectResolver);
    }
}
