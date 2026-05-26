<?php

namespace App\Http\Controllers\v1\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaystackWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaystackWebhookController extends Controller
{
    public function __construct(
        private readonly PaystackWebhookService $paystackWebhookService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();

        try {
            $this->assertValidSignature($payload, $request->header('x-paystack-signature'));
        } catch (RuntimeException $e) {
            Log::notice('Paystack webhook signature verification failed.', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid signature'], 400);
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($payload, true);
        if (! is_array($data)) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        try {
            $this->paystackWebhookService->handlePayload($data);
        } catch (\Throwable $e) {
            Log::error('Paystack webhook handler failed.', ['exception' => $e]);

            return response()->json(['message' => 'Processing failed'], 500);
        }

        return response()->json(['received' => true]);
    }

    /**
     * @throws RuntimeException
     */
    private function assertValidSignature(string $payload, ?string $signatureHeader): void
    {
        $secret = config('services.paystack.secret');
        if (! is_string($secret) || $secret === '') {
            if (app()->environment('production')) {
                throw new RuntimeException('PAYSTACK_SECRET_KEY is required in production.');
            }

            return;
        }

        if (! is_string($signatureHeader) || $signatureHeader === '') {
            if (app()->environment('production')) {
                throw new RuntimeException('Missing Paystack signature header.');
            }

            return;
        }

        $computed = hash_hmac('sha512', $payload, $secret);
        if (! hash_equals($computed, $signatureHeader)) {
            throw new RuntimeException('Paystack signature mismatch.');
        }
    }
}
