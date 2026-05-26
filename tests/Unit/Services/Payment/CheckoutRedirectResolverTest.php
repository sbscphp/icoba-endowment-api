<?php

namespace Tests\Unit\Services\Payment;

use App\Services\Payment\CheckoutRedirectResolver;
use Tests\TestCase;

final class CheckoutRedirectResolverTest extends TestCase
{
    private CheckoutRedirectResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CheckoutRedirectResolver;
    }

    public function test_stripe_defaults_use_donate_success_and_donate_failed(): void
    {
        $urls = $this->resolver->resolve('stripe', null, null, null, 'https://app.example.com');

        $this->assertSame('https://app.example.com/donate/success?session_id={CHECKOUT_SESSION_ID}', $urls['success_url']);
        $this->assertSame('https://app.example.com/donate/failed', $urls['failed_url']);
    }

    public function test_paystack_defaults_use_donate_success_and_donate_failed(): void
    {
        $urls = $this->resolver->resolve('paystack', null, null, null, 'https://app.example.com');

        $this->assertSame('https://app.example.com/donate/success', $urls['success_url']);
        $this->assertSame('https://app.example.com/donate/failed', $urls['failed_url']);
    }

    public function test_failed_url_prefers_explicit_value_over_legacy_cancel_url(): void
    {
        $urls = $this->resolver->resolve(
            'stripe',
            null,
            'https://app.example.com/custom/failed',
            'https://app.example.com/legacy/cancel',
            null,
        );

        $this->assertSame('https://app.example.com/custom/failed', $urls['failed_url']);
    }

    public function test_legacy_cancel_url_is_used_when_failed_url_is_missing(): void
    {
        $urls = $this->resolver->resolve(
            'stripe',
            null,
            null,
            'https://app.example.com/legacy/cancel',
            null,
        );

        $this->assertSame('https://app.example.com/legacy/cancel', $urls['failed_url']);
    }
}
