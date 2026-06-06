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
        ]);

        $this->challengeTokenService = app(ChallengeTokenService::class);
        $this->otpService = app(OtpService::class);
    }

    public function test_decode_rejects_expired_challenge_token(): void
    {
        $token = $this->issueTokenForUser(User::factory()->unverified()->create(), ttlSeconds: 60);

        $this->travel(61)->seconds();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid or expired verification session.');

        $this->challengeTokenService->decode($token, OtpPurposeEnum::EMAIL_VERIFICATION);
    }

    public function test_decode_for_resend_accepts_expired_challenge_token(): void
    {
        $token = $this->issueTokenForUser(User::factory()->unverified()->create(), ttlSeconds: 60);

        $this->travel(61)->seconds();

        $payload = $this->challengeTokenService->decodeForResend($token, OtpPurposeEnum::EMAIL_VERIFICATION);

        $this->assertSame(OtpPurposeEnum::EMAIL_VERIFICATION->value, $payload['purpose']);
        $this->assertArrayHasKey('subject_id', $payload);
    }

    public function test_resend_after_countdown_expires_issues_fresh_otp_for_unverified_user(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();
        $token = $this->issueTokenForUser($user, ttlSeconds: 300);

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
        $token = $this->issueTokenForUser($user, ttlSeconds: 60);

        $this->travel(61)->seconds();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('This email address has already been verified.');

        $this->otpService->resendEmailVerificationOtp($token);
    }

    private function issueTokenForUser(User $user, int $ttlSeconds): string
    {
        $challenge = AuthChallenge::create([
            'uuid' => (string) Str::uuid(),
            'subject_type' => UserTypeEnum::CUSTOMER->value,
            'subject_id' => (string) $user->uuid,
            'purpose' => OtpPurposeEnum::EMAIL_VERIFICATION,
            'channel' => OtpChannelEnum::EMAIL->value,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return $this->challengeTokenService->issue($challenge, $ttlSeconds);
    }
}
