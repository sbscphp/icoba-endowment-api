<?php

namespace Tests\Unit\Services\Auth;

use App\Enums\OtpChannelEnum;
use App\Enums\OtpPurposeEnum;
use App\Mail\OTPMail;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Support\SmsMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpStubModeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SmsMode::setOverride(null);

        parent::tearDown();
    }

    public function test_stub_mode_uses_random_code_for_email_login_otp(): void
    {
        SmsMode::setOverride(SmsMode::STUB);
        config(['services.sms.stub_code' => '123456']);

        Mail::fake();

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'phone_number' => '8012345678',
            'country_code' => '+234',
        ]);

        $service = app(\App\Services\Auth\OtpService::class);
        $service->sendLoginOtp($user, OtpChannelEnum::EMAIL);

        $challenge = AuthChallenge::query()
            ->where('subject_id', $user->uuid)
            ->where('purpose', OtpPurposeEnum::LOGIN->value)
            ->where('channel', OtpChannelEnum::EMAIL->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($challenge);
        $this->assertFalse(Hash::check('123456', (string) $challenge->code_hash));
        Mail::assertSent(OTPMail::class);
    }

    public function test_stub_mode_uses_fixed_code_for_sms_login_otp(): void
    {
        SmsMode::setOverride(SmsMode::STUB);
        config(['services.sms.stub_code' => '123456']);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'phone_number' => '8012345678',
            'country_code' => '+234',
        ]);

        $service = app(\App\Services\Auth\OtpService::class);
        $service->sendLoginOtp($user, OtpChannelEnum::SMS);

        $challenge = AuthChallenge::query()
            ->where('subject_id', $user->uuid)
            ->where('purpose', OtpPurposeEnum::LOGIN->value)
            ->where('channel', OtpChannelEnum::SMS->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($challenge);
        $this->assertTrue(Hash::check('123456', (string) $challenge->code_hash));
    }
}
