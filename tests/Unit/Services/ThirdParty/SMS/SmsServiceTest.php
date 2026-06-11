<?php

namespace Tests\Unit\Services\ThirdParty\SMS;

use App\Support\SmsMode;
use App\Services\ThirdParty\SMS\InfobipService;
use App\Services\ThirdParty\SMS\SmsService;
use App\Services\ThirdParty\SMS\TermiiService;
use App\Services\ThirdParty\SMS\TwilioService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SmsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SmsMode::setOverride(SmsMode::LIVE);

        config([
            'app.name' => 'Test App',
            'services.sms.foreign_provider' => 'twilio',
        ]);
    }

    protected function tearDown(): void
    {
        SmsMode::setOverride(null);

        parent::tearDown();
    }

    public function test_routes_nigerian_numbers_to_termii_when_not_log(): void
    {
        $termii = $this->mock(TermiiService::class);
        $termii->shouldReceive('send')
            ->once()
            ->with('2348031234567', \Mockery::type('string'))
            ->andReturn(['sent' => true]);

        $this->mock(TwilioService::class)->shouldNotReceive('send');
        $this->mock(InfobipService::class)->shouldNotReceive('send');

        $service = app(SmsService::class);
        $service->sendOtp(null, '+2348031234567', '123456', 5, 'login');
    }

    public function test_routes_foreign_numbers_to_configured_foreign_provider(): void
    {
        config(['services.sms.foreign_provider' => 'infobip']);

        $infobip = $this->mock(InfobipService::class);
        $infobip->shouldReceive('send')
            ->once()
            ->with('447123456789', \Mockery::type('string'))
            ->andReturn(['sent' => true]);

        $this->mock(TermiiService::class)->shouldNotReceive('send');
        $this->mock(TwilioService::class)->shouldNotReceive('send');

        $service = app(SmsService::class);
        $service->sendOtp(null, '+447123456789', '123456', 5, 'login');
    }

    public function test_does_not_call_providers_when_mode_is_log(): void
    {
        SmsMode::setOverride(SmsMode::LOG);

        Log::shouldReceive('info')
            ->once()
            ->with('OTP SMS prepared', \Mockery::on(function (array $context): bool {
                return ($context['to'] ?? null) === '2348031234567'
                    && ($context['purpose'] ?? null) === 'login';
            }));

        $this->mock(TermiiService::class)->shouldNotReceive('send');
        $this->mock(TwilioService::class)->shouldNotReceive('send');
        $this->mock(InfobipService::class)->shouldNotReceive('send');

        $service = app(SmsService::class);
        $service->sendOtp(null, '+2348031234567', '123456', 5, 'login');
    }

    public function test_falls_back_to_twilio_for_invalid_foreign_provider(): void
    {
        config(['services.sms.foreign_provider' => 'unknown']);

        Log::shouldReceive('warning')
            ->once()
            ->with('SMS foreign provider invalid; falling back to twilio', \Mockery::type('array'));

        $twilio = $this->mock(TwilioService::class);
        $twilio->shouldReceive('send')
            ->once()
            ->with('447123456789', \Mockery::type('string'))
            ->andReturn(['sent' => true]);

        $service = app(SmsService::class);
        $service->sendOtp(null, '+447123456789', '123456', 5, 'login');
    }
}
