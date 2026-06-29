<?php

namespace App\Http\Controllers\v1\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Payment\FcmbCheckoutService;
use App\Services\Payment\FcmbPaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * CLNX / FCMB payment notification webhook (hosted checkout).
 */
class FcmbWebhookController extends Controller
{
    public function __construct(
        private readonly FcmbCheckoutService $fcmbCheckoutService,
        private readonly FcmbPaymentWebhookService $fcmbPaymentWebhookService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        /** @var array<string, mixed>|null $payload */
        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        try {
            $this->assertValidWebhook($payload);
        } catch (RuntimeException $e) {
            Log::notice('FCMB CLNX webhook verification failed.', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid webhook'], 400);
        }

        $handshakeId = $this->resolveHandshakeId($payload);

        try {
            $this->fcmbPaymentWebhookService->handlePayload($payload);
        } catch (\Throwable $e) {
            Log::error('FCMB CLNX webhook handler failed.', ['exception' => $e]);

            return response()->json(['message' => 'Processing failed'], 500);
        }

        return response()->json(['handshakeId' => $handshakeId]);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException
     */
    private function assertValidWebhook(array $payload): void
    {
        if (! $this->fcmbCheckoutService->verifyWebhookHash($payload)) {
            if (app()->environment('production')) {
                throw new RuntimeException('FCMB webhook hash mismatch.');
            }

            if (! (bool) config('services.fcmb.allow_test_webhooks', false)) {
                throw new RuntimeException('FCMB webhook hash mismatch.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveHandshakeId(array $payload): string
    {
        $fingerprint = hash('sha256', json_encode($this->handshakeFingerprint($payload)));
        $cacheKey = 'fcmb_clnx_webhook_handshake:'.$fingerprint;

        /** @var string|null $existing */
        $existing = Cache::get($cacheKey);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $handshakeId = (string) Str::uuid();
        Cache::forever($cacheKey, $handshakeId);

        return $handshakeId;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function handshakeFingerprint(array $payload): array
    {
        return [
            'amount' => $payload['amount'] ?? null,
            'reference' => $payload['reference'] ?? null,
            'invoiceRequestReference' => $payload['invoiceRequestReference'] ?? null,
            'status' => $payload['status'] ?? null,
            'transactionDate' => $payload['transactionDate'] ?? null,
        ];
    }
}
