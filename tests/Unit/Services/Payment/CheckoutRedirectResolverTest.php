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

    public function test_falls_back_to_app_frontend_url_when_nothing_is_sent_or_configured(): void
    {
        config([
            'app.frontend_url' => 'https://frontend.example.com/',
            'services.fcmb.success_url' => null,
            'services.fcmb.failed_url' => null,
        ]);

        $urls = $this->resolver->resolve('fcmb', null, null, null, null);

        $this->assertSame('https://frontend.example.com/donate/success', $urls['success_url']);
        $this->assertSame('https://frontend.example.com/donate/failed', $urls['failed_url']);
    }

    public function test_gateway_env_override_takes_precedence_over_app_frontend_url(): void
    {
        config([
            'app.frontend_url' => 'https://frontend.example.com',
            'services.fcmb.success_url' => 'https://override.example.com/ok',
            'services.fcmb.failed_url' => 'https://override.example.com/nope',
        ]);

        $urls = $this->resolver->resolve('fcmb', null, null, null, null);

        $this->assertSame('https://override.example.com/ok', $urls['success_url']);
        $this->assertSame('https://override.example.com/nope', $urls['failed_url']);
    }

    public function test_request_frontend_url_takes_precedence_over_app_frontend_url(): void
    {
        config([
            'app.frontend_url' => 'https://frontend.example.com',
            'services.paystack.callback_url' => null,
            'services.paystack.failed_url' => null,
        ]);

        $urls = $this->resolver->resolve('paystack', null, null, null, 'https://request.example.com');

        $this->assertSame('https://request.example.com/donate/success', $urls['success_url']);
        $this->assertSame('https://request.example.com/donate/failed', $urls['failed_url']);
    }

    public function test_stripe_app_frontend_fallback_gets_session_placeholder(): void
    {
        config([
            'app.frontend_url' => 'https://frontend.example.com',
            'services.stripe.success_url' => null,
            'services.stripe.failed_url' => null,
            'services.stripe.cancel_url' => null,
        ]);

        $urls = $this->resolver->resolve('stripe', null, null, null, null);

        $this->assertSame('https://frontend.example.com/donate/success?session_id={CHECKOUT_SESSION_ID}', $urls['success_url']);
        $this->assertSame('https://frontend.example.com/donate/failed', $urls['failed_url']);
    }

    public function test_never_falls_back_to_netlify_domain(): void
    {
        config([
            'app.frontend_url' => 'https://frontend.example.com',
            'services.fcmb.success_url' => null,
            'services.fcmb.failed_url' => null,
        ]);

        $urls = $this->resolver->resolve('fcmb', null, null, null, null);

        $this->assertStringNotContainsString('netlify', $urls['success_url']);
        $this->assertStringNotContainsString('netlify', $urls['failed_url']);
    }
}
