<?php

namespace App\Services\Auth;

use App\Enums\OtpChannelEnum;
use App\Enums\OtpPurposeEnum;
use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\OpaqueMessageHelper;
use App\Mail\OTPMail;
use App\Models\Admin;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Services\Theme\ThemeResolver;
use App\Services\ThirdParty\SMS\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OtpService
{
    private const RESET_TOKEN_EXP_MINUTES = 15;

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly ChallengeTokenService $challengeTokenService,
        private readonly SmsService $smsService,
    ) {}

    private function otpExpiryMinutes(): int
    {
        return max(1, (int) config('security.otp_minutes', 5));
    }

    private function otpSendCooldownSeconds(): int
    {
        return max(1, (int) config('security.otp_send_cooldown_seconds'));
    }

    public function sendLoginOtp(User|Admin $subject): array
    {
        return $this->sendOtp($subject, OtpPurposeEnum::LOGIN, 'login', OtpChannelEnum::EMAIL);
    }

    public function verifyLoginOtp(string $challengeToken, string $otpCode): User|Admin
    {
        return $this->verifyOtp($challengeToken, $otpCode, OtpPurposeEnum::LOGIN);
    }

    public function resendLoginOtp(string $challengeToken): array
    {
        $payload = $this->challengeTokenService->decodeForResend($challengeToken, OtpPurposeEnum::LOGIN);
        $subject = $this->resolveSubjectFromPayload($payload);

        return $this->sendOtp($subject, OtpPurposeEnum::LOGIN, 'login', OtpChannelEnum::EMAIL);
    }

    public function sendEmailVerificationOtp(User $user, OtpChannelEnum $channel = OtpChannelEnum::EMAIL): array
    {
        return $this->sendOtp($user, OtpPurposeEnum::EMAIL_VERIFICATION, 'email verification', $channel);
    }

    /**
     * @throws ApiException
     */
    public function verifyEmailVerificationOtp(string $challengeToken, string $otpCode): User
    {
        $subject = $this->verifyOtp($challengeToken, $otpCode, OtpPurposeEnum::EMAIL_VERIFICATION);

        if (! $subject instanceof User) {
            throw new ApiException('Invalid verification session.', 422);
        }

        return $subject;
    }

    public function resendEmailVerificationOtp(string $challengeToken, ?OtpChannelEnum $channel = null): array
    {
        $payload = $this->challengeTokenService->decodeForResend($challengeToken, OtpPurposeEnum::EMAIL_VERIFICATION);
        $subject = $this->resolveSubjectFromPayload($payload);

        if (! $subject instanceof User) {
            throw new ApiException('Invalid verification session.', 422);
        }

        if ($subject->email_verified_at !== null) {
            throw new ApiException('This email address has already been verified.', 422);
        }

        $channel ??= $this->channelFromPayload($payload);

        return $this->sendOtp($subject, OtpPurposeEnum::EMAIL_VERIFICATION, 'email verification', $channel);
    }

    public function sendPasswordResetOtp(User|Admin $subject, OtpChannelEnum $channel = OtpChannelEnum::EMAIL): array
    {
        return $this->sendOtp($subject, OtpPurposeEnum::PASSWORD_RESET, 'password reset', $channel);
    }

    public function verifyPasswordResetOtp(string $challengeToken, string $otpCode): array
    {
        $subject = $this->verifyOtp($challengeToken, $otpCode, OtpPurposeEnum::PASSWORD_RESET);

        return [
            'reset_token' => encrypt([
                'subject_type' => $subject instanceof Admin ? UserTypeEnum::ADMIN->value : UserTypeEnum::CUSTOMER->value,
                'subject_id' => (string) $subject->uuid,
                'purpose' => 'RESET_PASSWORD',
                'exp' => now()->addMinutes(self::RESET_TOKEN_EXP_MINUTES)->timestamp,
            ]),
            'expires_in' => self::RESET_TOKEN_EXP_MINUTES * 60,
        ];
    }

    public function resendPasswordResetOtp(string $challengeToken, ?OtpChannelEnum $channel = null): array
    {
        $payload = $this->challengeTokenService->decodeForResend($challengeToken, OtpPurposeEnum::PASSWORD_RESET);
        $subject = $this->resolveSubjectFromPayload($payload);

        $channel ??= $this->channelFromPayload($payload);

        return $this->sendOtp($subject, OtpPurposeEnum::PASSWORD_RESET, 'password reset', $channel);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendOtp(User|Admin $subject, OtpPurposeEnum $purpose, string $purposeLabel, OtpChannelEnum $channel): array
    {
        $this->assertChannelSupported($subject, $channel);

        $gate = $this->evaluateOtpSendGate($subject, $purpose, $channel);

        if ($gate['mode'] === 'blocked') {
            throw new ApiException(
                OpaqueMessageHelper::MESSAGE_OTP_COOLDOWN_ACTIVE,
                429,
                [
                    'retry_after_seconds' => $gate['retry_after_seconds'],
                    'retry_after_human' => OpaqueMessageHelper::humanizeSecondsRemaining($gate['retry_after_seconds']),
                    'cooldown_active' => true,
                ]
            );
        }

        if ($gate['mode'] === 'reuse') {
            /** @var AuthChallenge $challenge */
            $challenge = $gate['challenge'];
            $ttlRemaining = max(1, $challenge->expires_at->getTimestamp() - time());

            return [
                'challenge_token' => $this->challengeTokenService->issue($challenge, $ttlRemaining),
                'expires_in' => $ttlRemaining,
                'cooldown_active' => true,
                'retry_after_seconds' => $gate['retry_after_seconds'],
                'retry_after_human' => OpaqueMessageHelper::humanizeSecondsRemaining($gate['retry_after_seconds']),
                'otp_purpose' => $purpose->value,
                'otp_channel' => $challenge->channel,
            ];
        }

        [$plainOtp, $challenge] = DB::transaction(function () use ($subject, $purpose, $channel) {
            AuthChallenge::query()
                ->where('subject_type', $this->subjectType($subject))
                ->where('subject_id', $subject->uuid)
                ->where('purpose', $purpose->value)
                ->where('channel', $channel->value)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $otp = $this->generateOtpCode($channel);

            $challenge = AuthChallenge::create([
                'uuid' => (string) Str::uuid(),
                'subject_type' => $this->subjectType($subject),
                'subject_id' => (string) $subject->uuid,
                'purpose' => $purpose,
                'channel' => $channel->value,
                'code_hash' => Hash::make($otp),
                'expires_at' => now()->addMinutes($this->otpExpiryMinutes()),
            ]);

            return [$otp, $challenge];
        });

        $this->dispatchOtp($subject, $plainOtp, $purpose, $purposeLabel, $channel);

        $ttlSeconds = $this->otpExpiryMinutes() * 60;

        return [
            'challenge_token' => $this->challengeTokenService->issue($challenge, $ttlSeconds),
            'expires_in' => $ttlSeconds,
            'cooldown_active' => false,
            'otp_purpose' => $purpose->value,
            'otp_channel' => $channel->value,
        ];
    }

    private function dispatchOtp(User|Admin $subject, string $plainOtp, OtpPurposeEnum $purpose, string $purposeLabel, OtpChannelEnum $channel): void
    {
        if ($channel === OtpChannelEnum::EMAIL) {
            $theme = app(ThemeResolver::class)->resolveForMail();
            $recipientName = $subject instanceof User
                ? $subject->displayName()
                : (trim($subject->firstname.' '.$subject->lastname) ?: $subject->email);
            Mail::to($subject->email)->send(new OTPMail($plainOtp, $this->otpExpiryMinutes(), $recipientName, $theme, $purpose));

            return;
        }

        if ($this->smsDispatchEnabled()) {
            $this->smsService->sendOtp(
                data_get($subject, 'country_code'),
                data_get($subject, 'phone_number'),
                $plainOtp,
                $this->otpExpiryMinutes(),
                $purposeLabel
            );

            return;
        }

        Log::info('OTP SMS stub: provider dispatch skipped', [
            'subject_type' => $this->subjectType($subject),
            'subject_id' => $subject->uuid,
            'purpose' => $purpose->value,
            'phone' => data_get($subject, 'phone_number'),
        ]);
    }

    private function generateOtpCode(OtpChannelEnum $channel): string
    {
        if ($channel === OtpChannelEnum::SMS && ! $this->smsDispatchEnabled()) {
            $stub = (string) config('security.otp_sms_stub_code', '123456');

            return preg_match('/^\d{6}$/', $stub) === 1 ? $stub : '123456';
        }

        return (string) random_int(100000, 999999);
    }

    private function smsDispatchEnabled(): bool
    {
        return (bool) config('security.otp_sms_dispatch_enabled', false);
    }

    private function assertChannelSupported(User|Admin $subject, OtpChannelEnum $channel): void
    {
        if ($channel !== OtpChannelEnum::SMS) {
            return;
        }

        if ($this->subjectHasSmsDestination($subject)) {
            return;
        }

        throw new ApiException('A valid phone number is required to receive verification codes by SMS.', 422);
    }

    private function subjectHasSmsDestination(User|Admin $subject): bool
    {
        return trim((string) data_get($subject, 'phone_number')) !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function channelFromPayload(array $payload): OtpChannelEnum
    {
        if (! empty($payload['challenge_uuid'])) {
            $challenge = AuthChallenge::query()->where('uuid', $payload['challenge_uuid'])->first();
            if ($challenge !== null) {
                return OtpChannelEnum::tryFrom((string) $challenge->channel) ?? OtpChannelEnum::EMAIL;
            }
        }

        return OtpChannelEnum::EMAIL;
    }

    /**
     * @return array{
     *     mode: 'send_new'|'reuse'|'blocked',
     *     challenge?: AuthChallenge,
     *     retry_after_seconds?: int
     * }
     */
    private function evaluateOtpSendGate(User|Admin $subject, OtpPurposeEnum $purpose, OtpChannelEnum $channel): array
    {
        $cooldownSec = $this->otpSendCooldownSeconds();
        $subjectType = $this->subjectType($subject);

        $last = AuthChallenge::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subject->uuid)
            ->where('purpose', $purpose->value)
            ->where('channel', $channel->value)
            ->orderByDesc('id')
            ->first();

        if ($last === null) {
            return ['mode' => 'send_new'];
        }

        $elapsed = now()->getTimestamp() - $last->created_at->getTimestamp();
        if ($elapsed >= $cooldownSec) {
            return ['mode' => 'send_new'];
        }

        $retryAfter = max(1, $cooldownSec - $elapsed);

        $active = AuthChallenge::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subject->uuid)
            ->where('purpose', $purpose->value)
            ->where('channel', $channel->value)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if ($active !== null) {
            return ['mode' => 'reuse', 'challenge' => $active, 'retry_after_seconds' => $retryAfter];
        }

        return ['mode' => 'blocked', 'retry_after_seconds' => $retryAfter];
    }

    private function verifyOtp(string $challengeToken, string $otpCode, OtpPurposeEnum $purpose): User|Admin
    {
        $payload = $this->challengeTokenService->decode($challengeToken, $purpose);

        $challenge = AuthChallenge::query()
            ->where('uuid', $payload['challenge_uuid'])
            ->where('subject_type', $payload['subject_type'])
            ->where('subject_id', $payload['subject_id'])
            ->where('purpose', $purpose->value)
            ->first();

        if (! $challenge || $challenge->used_at || now()->greaterThan($challenge->expires_at)) {
            throw new ApiException('Invalid or expired verification code.', 422);
        }

        $latest = AuthChallenge::query()
            ->where('subject_type', $challenge->subject_type)
            ->where('subject_id', $challenge->subject_id)
            ->where('purpose', $purpose->value)
            ->where('channel', $challenge->channel)
            ->whereNull('used_at')
            ->orderByDesc('id')
            ->first();

        if (! $latest || $latest->id !== $challenge->id || $challenge->attempts >= self::MAX_ATTEMPTS) {
            throw new ApiException('Invalid or expired verification code.', 422);
        }

        $challenge->increment('attempts');

        if (! Hash::check($otpCode, $challenge->code_hash)) {
            throw new ApiException('Invalid or expired verification code.', 422);
        }

        $challenge->forceFill(['used_at' => now()])->save();

        return $this->resolveSubject($challenge);
    }

    private function subjectType(User|Admin $subject): string
    {
        return $subject instanceof Admin ? UserTypeEnum::ADMIN->value : UserTypeEnum::CUSTOMER->value;
    }

    private function resolveSubject(AuthChallenge $challenge): User|Admin
    {
        if ($challenge->subject_type === UserTypeEnum::ADMIN->value) {
            return Admin::query()->where('uuid', $challenge->subject_id)->firstOrFail();
        }

        return User::query()->where('uuid', $challenge->subject_id)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSubjectFromPayload(array $payload): User|Admin
    {
        if ($payload['subject_type'] === UserTypeEnum::ADMIN->value) {
            return Admin::query()->where('uuid', $payload['subject_id'])->firstOrFail();
        }

        return User::query()->where('uuid', $payload['subject_id'])->firstOrFail();
    }
}
