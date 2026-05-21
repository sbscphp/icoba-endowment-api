<?php

namespace App\Http\Controllers\v1\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\StripeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeWebhookService $stripeWebhookService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();

        try {
            $event = $this->parseEvent($payload, $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException $e) {
            Log::notice('Stripe webhook signature verification failed.', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid signature'], 400);
        } catch (RuntimeException $e) {
            Log::error('Stripe webhook configuration error.', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Webhook misconfigured'], 500);
        }

        try {
            $this->stripeWebhookService->handleEvent($event);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook handler failed.', ['exception' => $e]);

            return response()->json(['message' => 'Processing failed'], 500);
        }

        return response()->json(['received' => true]);
    }

    /**
     * @throws SignatureVerificationException
     */
    private function parseEvent(string $payload, ?string $sigHeader): Event
    {
        $secret = config('services.stripe.webhook_secret');
        if (is_string($secret) && $secret !== '') {
            return Webhook::constructEvent($payload, $sigHeader ?? '', $secret);
        }

        if (app()->environment('production')) {
            throw new RuntimeException('STRIPE_WEBHOOK_SECRET is required in production.');
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($payload, true);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid webhook JSON.');
        }

        return Event::constructFrom($data);
    }
}
