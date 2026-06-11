<?php

namespace Tests\Unit\Services\Auth;

use App\Enums\OtpChannelEnum;
use App\Enums\OtpPurposeEnum;
use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Mail\OTPMail;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Services\Auth\ChallengeTokenService;
use App\Services\Auth\OtpService;
use App\Support\SmsMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmailVerificationResendTest extends TestCase
{
    use RefreshDatabase;

    private ChallengeTokenService $challengeTokenService;

    private OtpService $otpService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'security.otp_minutes' => 5,
            'security.otp_send_cooldown_seconds' => 300,
            'security.otp_resend_token_max_minutes' => 30,
        ]);

        $this->challengeTokenService = app(ChallengeTokenService::class);
        $this->otpService = app(OtpService::class);

        SmsMode::setOverride(SmsMode::LOG);
    }

    protected function tearDown(): void
    {
        SmsMode::setOverride(null);

        parent::tearDown();
    }

    public function test_decode_rejects_expired_challenge_token(): void
    {
        $token = $this->issueTokenForUser(User::factory()->unverified()->create(), OtpPurposeEnum::EMAIL_VERIFICATION, ttlSeconds: 60);

        $this->travel(61)->seconds();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid or expired verification session.');

        $this->challengeTokenService->decode($token, OtpPurposeEnum::EMAIL_VERIFICATION);
    }

    public function test_decode_for_resend_accepts_expired_challenge_token(): void
    {
        $token = $this->issueTokenForUser(User::factory()->unverified()->create(), OtpPurposeEnum::EMAIL_VERIFICATION, ttlSeconds: 60);

        $this->travel(61)->seconds();

        $payload = $this->challengeTokenService->decodeForResend($token, OtpPurposeEnum::EMAIL_VERIFICATION);

        $this->assertSame(OtpPurposeEnum::EMAIL_VERIFICATION->value, $payload['purpose']);
        $this->assertArrayHasKey('subject_id', $payload);
    }

    public function test_decode_for_resend_rejects_token_past_max_reuse_window(): void
    {
        config(['security.otp_resend_token_max_minutes' => 10]);

        $token = $this->issueTokenForUser(User::factory()->unverified()->create(), OtpPurposeEnum::EMAIL_VERIFICATION, ttlSeconds: 60);

        $this->travel(601)->seconds();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid or expired verification session.');

        $this->challengeTokenService->decodeForResend($token, OtpPurposeEnum::EMAIL_VERIFICATION);
    }

    public function test_resend_after_countdown_expires_issues_fresh_otp_for_unverified_user(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();
        $token = $this->issueTokenForUser($user, OtpPurposeEnum::EMAIL_VERIFICATION, ttlSeconds: 300);

        $this->travel(315)->seconds();

        $result = $this->otpService->resendEmailVerificationOtp($token);

        $this->assertFalse($result['cooldown_active']);
        $this->assertSame(OtpPurposeEnum::EMAIL_VERIFICATION->value, $result['otp_purpose']);
        $this->assertNotSame($token, $result['challenge_token']);
        $this->assertGreaterThan(0, $result['expires_in']);

        Mail::assertSent(OTPMail::class);

        $this->assertDatabaseHas('auth_challenges', [
            'subject_type' => UserTypeEnum::CUSTOMER->value,
            'subject_id' => $user->uuid,
            'purpose' => OtpPurposeEnum::EMAIL_VERIFICATION->value,
            'channel' => OtpChannelEnum::EMAIL->value,
            'used_at' => null,
        ]);
    }

    public function test_resend_with_expired_token_rejects_already_verified_user(): void
    {
        $user = User::factory()->create();
        $token = $this->issueTokenForUser($user, OtpPurposeEnum::EMAIL_VERIFICATION, ttlSeconds: 60);

        $this->travel(61)->seconds();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('This email address has already been verified.');

        $this->otpService->resendEmailVerificationOtp($token);
    }

    public function test_password_reset_resend_after_countdown_expires_issues_fresh_otp(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $token = $this->issueTokenForUser($user, OtpPurposeEnum::PASSWORD_RESET, ttlSeconds: 300);

        $this->travel(315)->seconds();

        $result = $this->otpService->resendPasswordResetOtp($token);

        $this->assertFalse($result['cooldown_active']);
        $this->assertSame(OtpPurposeEnum::PASSWORD_RESET->value, $result['otp_purpose']);
        $this->assertNotSame($token, $result['challenge_token']);

        Mail::assertSent(OTPMail::class);
    }

    public function test_login_resend_after_countdown_expires_issues_fresh_otp(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $token = $this->issueTokenForUser($user, OtpPurposeEnum::LOGIN, ttlSeconds: 300);

        $this->travel(315)->seconds();

        $result = $this->otpService->resendLoginOtp($token);

        $this->assertFalse($result['cooldown_active']);
        $this->assertSame(OtpPurposeEnum::LOGIN->value, $result['otp_purpose']);
        $this->assertNotSame($token, $result['challenge_token']);

        Mail::assertSent(OTPMail::class);
    }

    public function test_fresh_otp_challenge_expiry_matches_token_ttl(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();
        $result = $this->otpService->sendEmailVerificationOtp($user, OtpChannelEnum::EMAIL);

        $challenge = AuthChallenge::query()->whereNull('used_at')->orderByDesc('id')->firstOrFail();
        $payload = decrypt($result['challenge_token']);

        $challengeTtl = $challenge->expires_at->getTimestamp() - $challenge->created_at->getTimestamp();
        $tokenTtl = (int) ($payload['exp'] ?? 0) - (int) ($payload['iat'] ?? 0);

        $this->assertSame(300, $result['expires_in']);
        $this->assertGreaterThanOrEqual(299, $challengeTtl);
        $this->assertLessThanOrEqual(300, $challengeTtl);
        $this->assertSame($challenge->expires_at->getTimestamp(), (int) $payload['exp']);
        $this->assertGreaterThanOrEqual(299, $tokenTtl);
    }

    public function test_wrong_otp_then_correct_otp_succeeds_with_same_resend_token(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();
        $signup = $this->otpService->sendEmailVerificationOtp($user, OtpChannelEnum::EMAIL);

        $this->travel(315)->seconds();

        $resend = $this->otpService->resendEmailVerificationOtp($signup['challenge_token']);
        $token = $resend['challenge_token'];

        $challenge = AuthChallenge::query()->whereNull('used_at')->orderByDesc('id')->firstOrFail();
        $plainOtp = '654321';
        $challenge->forceFill(['code_hash' => Hash::make($plainOtp)])->save();

        try {
            $this->otpService->verifyEmailVerificationOtp($token, '654322');
            $this->fail('Expected wrong OTP to fail.');
        } catch (ApiException $e) {
            $this->assertSame('Invalid or expired verification code.', $e->getMessage());
        }

        $verified = $this->otpService->verifyEmailVerificationOtp($token, $plainOtp);

        $this->assertSame($user->uuid, $verified->uuid);
        $this->assertSame(2, (int) $challenge->fresh()->attempts);
        $this->assertNotNull($challenge->fresh()->used_at);
    }

    public function test_login_resend_honours_requested_otp_channel(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'phone_number' => '8012345678',
            'country_code' => '+234',
        ]);
        $token = $this->issueTokenForUser($user, OtpPurposeEnum::LOGIN, ttlSeconds: 300, channel: OtpChannelEnum::EMAIL);

        $this->travel(315)->seconds();

        $result = $this->otpService->resendLoginOtp($token, OtpChannelEnum::SMS);

        $this->assertSame(OtpChannelEnum::SMS->value, $result['otp_channel']);
        $this->assertFalse($result['cooldown_active']);

        $this->assertDatabaseHas('auth_challenges', [
            'subject_id' => $user->uuid,
            'purpose' => OtpPurposeEnum::LOGIN->value,
            'channel' => OtpChannelEnum::SMS->value,
            'used_at' => null,
        ]);
    }

    public function test_login_resend_without_channel_reuses_challenge_channel_from_token(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $token = $this->issueTokenForUser($user, OtpPurposeEnum::LOGIN, ttlSeconds: 300, channel: OtpChannelEnum::EMAIL);

        $this->travel(315)->seconds();

        $result = $this->otpService->resendLoginOtp($token);

        $this->assertSame(OtpChannelEnum::EMAIL->value, $result['otp_channel']);
    }

    private function issueTokenForUser(
        User $user,
        OtpPurposeEnum $purpose,
        int $ttlSeconds,
        OtpChannelEnum $channel = OtpChannelEnum::EMAIL,
    ): string {
        $challenge = AuthChallenge::create([
            'uuid' => (string) Str::uuid(),
            'subject_type' => UserTypeEnum::CUSTOMER->value,
            'subject_id' => (string) $user->uuid,
            'purpose' => $purpose,
            'channel' => $channel->value,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return $this->challengeTokenService->issue($challenge, $ttlSeconds);
    }
}
