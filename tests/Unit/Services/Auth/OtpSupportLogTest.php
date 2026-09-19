<?php

namespace Tests\Unit\Services\Auth;

use App\Enums\OtpChannelEnum;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class OtpSupportLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_otp_is_written_to_the_otp_channel_when_enabled(): void
    {
        config(['security.otp_log_codes' => true]);
        Mail::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $logged = null;

        $channel = Mockery::mock();
        $channel->shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$logged): bool {
            $logged = $context;

            return $message === 'OTP issued';
        });
        Log::shouldReceive('channel')->with('otp')->once()->andReturn($channel);

        app(OtpService::class)->sendLoginOtp($user, OtpChannelEnum::EMAIL);

        $challenge = AuthChallenge::query()->where('subject_id', $user->uuid)->latest('id')->firstOrFail();

        $this->assertSame($user->email, $logged['email']);
        $this->assertSame($challenge->uuid, $logged['challenge_uuid']);
        $this->assertTrue(Hash::check($logged['otp'], (string) $challenge->code_hash));
    }

    public function test_nothing_is_logged_when_disabled(): void
    {
        config(['security.otp_log_codes' => false]);
        Mail::fake();
        Log::shouldReceive('channel')->never();

        app(OtpService::class)->sendLoginOtp(User::factory()->create(['email_verified_at' => now()]), OtpChannelEnum::EMAIL);

        $this->assertSame(1, AuthChallenge::query()->count());
    }
}
