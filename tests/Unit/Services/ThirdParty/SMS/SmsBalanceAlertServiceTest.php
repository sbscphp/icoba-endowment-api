<?php

namespace Tests\Unit\Services\ThirdParty\SMS;

use App\Mail\SmsBalanceLowEmail;
use App\Models\Theme;
use App\Services\Theme\ThemeResolver;
use App\Services\ThirdParty\SMS\SmsBalanceAlertService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SmsBalanceAlertServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();

        config([
            'sms-usage.termii' => [
                'monthly_budget' => 10000,
                'currency' => 'NGN',
                'warn_at' => [50.0, 75.0, 90.0],
                'notify' => [
                    'addresses' => ['alerts@example.com'],
                ],
            ],
        ]);

        $theme = new Theme([
            'brand_name' => 'Test',
            'accent_color' => '#122168',
        ]);

        $this->mock(ThemeResolver::class, function ($mock) use ($theme): void {
            $mock->shouldReceive('resolveForMail')->andReturn($theme);
        });
    }

    public function test_sends_email_when_threshold_is_first_crossed(): void
    {
        $service = app(SmsBalanceAlertService::class);

        $result = $service->notifyIfBudgetThresholdCrossed('termii', 4000.0);

        $this->assertTrue($result['notified']);
        Mail::assertSent(SmsBalanceLowEmail::class, function (SmsBalanceLowEmail $mail): bool {
            return ($mail->data['threshold_percent'] ?? null) === 50.0
                && ($mail->data['provider'] ?? null) === 'termii';
        });
    }

    public function test_does_not_resend_email_for_same_threshold_when_scheduled(): void
    {
        $service = app(SmsBalanceAlertService::class);

        $service->notifyIfBudgetThresholdCrossed('termii', 4000.0);
        $result = $service->notifyIfBudgetThresholdCrossed('termii', 3500.0);

        $this->assertFalse($result['notified']);
        Mail::assertSent(SmsBalanceLowEmail::class, 1);
    }

    public function test_sends_email_when_higher_threshold_is_crossed(): void
    {
        $service = app(SmsBalanceAlertService::class);

        $service->notifyIfBudgetThresholdCrossed('termii', 4000.0);
        $result = $service->notifyIfBudgetThresholdCrossed('termii', 2000.0);

        $this->assertTrue($result['notified']);
        Mail::assertSent(SmsBalanceLowEmail::class, 2);
    }

    public function test_force_notify_sends_email_even_when_threshold_was_already_crossed(): void
    {
        $service = app(SmsBalanceAlertService::class);

        $service->notifyIfBudgetThresholdCrossed('termii', 4000.0);
        $result = $service->notifyIfBudgetThresholdCrossed('termii', 3500.0, forceNotify: true);

        $this->assertTrue($result['notified']);
        Mail::assertSent(SmsBalanceLowEmail::class, 2);
    }
}
