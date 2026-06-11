<?php

namespace Tests\Unit\Support;

use App\Support\SmsMode;
use Tests\TestCase;

class SmsModeTest extends TestCase
{
    protected function tearDown(): void
    {
        SmsMode::setOverride(null);

        parent::tearDown();
    }

    public function test_stub_mode_uses_fixed_code_and_skips_sms_service(): void
    {
        SmsMode::setOverride(SmsMode::STUB);

        config(['services.sms.stub_code' => '654321']);

        $this->assertTrue(SmsMode::isStub());
        $this->assertFalse(SmsMode::invokesSmsService());
        $this->assertFalse(SmsMode::sendsViaProvider());
        $this->assertSame('654321', SmsMode::stubCode());
    }

    public function test_log_mode_invokes_sms_service_without_provider(): void
    {
        SmsMode::setOverride(SmsMode::LOG);

        $this->assertFalse(SmsMode::isStub());
        $this->assertTrue(SmsMode::invokesSmsService());
        $this->assertFalse(SmsMode::sendsViaProvider());
    }

    public function test_live_mode_sends_via_provider(): void
    {
        SmsMode::setOverride(SmsMode::LIVE);

        $this->assertTrue(SmsMode::invokesSmsService());
        $this->assertTrue(SmsMode::sendsViaProvider());
    }

    public function test_unknown_mode_defaults_to_stub(): void
    {
        SmsMode::setOverride(null);

        putenv('SMS_MODE=invalid');
        $_ENV['SMS_MODE'] = 'invalid';

        $this->assertSame(SmsMode::STUB, SmsMode::current());

        putenv('SMS_MODE');
        unset($_ENV['SMS_MODE']);
    }
}
