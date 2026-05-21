<?php

namespace App\Services\Auth;

use App\Enums\AuditActionEnum;
use App\Enums\ModuleEnums;
use App\Enums\OtpChannelEnum;
use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Helpers\OpaqueMessageHelper;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordResetService
{
    private const DUMMY_BCRYPT_FOR_TIMING = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    public function requestReset(string $email, OtpChannelEnum $channel, Request $request): array
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            if (! OpaqueMessageHelper::authOpaqueEnabled('forgot_password')) {
                throw new ApiException('No account found for this email address.', 404);
            }

            // Keep missing-email behavior close to a real account branch and return a decoy token.
            Hash::check(Str::random(16), self::DUMMY_BCRYPT_FOR_TIMING);
            GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::PASSWORD_RESET_REQUESTED, $request, null, [
                'identifier_hash' => hash('sha256', $email),
            ], 'Password reset was requested for a customer identifier.', null, null, ModuleEnums::authentication, 200);

            return [
                'challenge_token' => Str::random(96),
                'expires_in' => max(1, (int) config('security.otp_minutes', 5)) * 60,
            ];
        }

        $payload = $this->otpService->sendPasswordResetOtp($user, $channel);
        GeneralHelper::storeAuditLog(
            UserTypeEnum::CUSTOMER,
            AuditActionEnum::PASSWORD_RESET_REQUESTED,
            $request,
            $user->uuid,
            ['otp_channel' => $channel->value],
            $this->displayName($user).' requested a password reset.',
            User::class,
            $user->uuid,
            ModuleEnums::authentication,
            200,
        );
        GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_SENT, $request, $user->uuid, [
            'purpose' => 'PASSWORD_RESET',
            'otp_channel' => $channel->value,
            'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
        ], 'Password reset OTP was sent.', User::class, $user->uuid, ModuleEnums::authentication, 200);

        return $payload;
    }

    public function requestAdminReset(string $email, Request $request): array
    {
        $admin = Admin::query()->where('email', $email)->first();

        if (! $admin) {
            if (! OpaqueMessageHelper::authOpaqueEnabled('forgot_password')) {
                throw new ApiException('No account found for this email address.', 404);
            }

            Hash::check(Str::random(16), self::DUMMY_BCRYPT_FOR_TIMING);
            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::PASSWORD_RESET_REQUESTED,
                $request,
                null,
                ['identifier_hash' => hash('sha256', $email)],
                'Password reset was requested for an admin identifier.',
                Admin::class,
                null,
                ModuleEnums::authentication,
                200
            );

            return [
                'challenge_token' => Str::random(96),
                'expires_in' => max(1, (int) config('security.otp_minutes', 5)) * 60,
            ];
        }

        $payload = $this->otpService->sendPasswordResetOtp($admin);

        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::PASSWORD_RESET_REQUESTED,
            $request,
            $admin->uuid,
            [],
            $this->displayName($admin).' requested a password reset.',
            Admin::class,
            $admin->uuid,
            ModuleEnums::authentication,
            200
        );

        GeneralHelper::storeAuditLog(UserTypeEnum::ADMIN, AuditActionEnum::OTP_SENT, $request, $admin->uuid, [
            'purpose' => 'PASSWORD_RESET',
            'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
        ], 'Password reset OTP was sent.', Admin::class, $admin->uuid, ModuleEnums::authentication, 200);

        return $payload;
    }

    public function resendResetOtp(string $challengeToken, ?OtpChannelEnum $channel, Request $request): array
    {
        $payload = $this->otpService->resendPasswordResetOtp($challengeToken, $channel);
        GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_SENT, $request, null, [
            'purpose' => 'PASSWORD_RESET',
            'otp_channel' => $payload['otp_channel'] ?? $channel?->value,
            'resend' => true,
            'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
        ], 'Password reset OTP was resent.', null, null, ModuleEnums::authentication, 200);

        return $payload;
    }

    public function resendAdminResetOtp(string $challengeToken, Request $request): array
    {
        $payload = $this->otpService->resendPasswordResetOtp($challengeToken);
        GeneralHelper::storeAuditLog(UserTypeEnum::ADMIN, AuditActionEnum::OTP_SENT, $request, null, [
            'purpose' => 'PASSWORD_RESET',
            'resend' => true,
            'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
        ], 'Password reset OTP was resent.', null, null, ModuleEnums::authentication, 200);

        return $payload;
    }

    public function verifyResetOtp(string $challengeToken, string $otp, Request $request): array
    {
        try {
            $payload = $this->otpService->verifyPasswordResetOtp($challengeToken, $otp);
        } catch (\Throwable $th) {
            GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_FAILED, $request, null, [
                'purpose' => 'PASSWORD_RESET',
            ], 'Password reset OTP verification failed.', null, null, ModuleEnums::authentication, 422);

            throw $th;
        }

        GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_VERIFIED, $request, null, [
            'purpose' => 'PASSWORD_RESET',
        ], 'Password reset OTP verified successfully.', null, null, ModuleEnums::authentication, 200);

        return $payload;
    }

    public function verifyAdminResetOtp(string $challengeToken, string $otp, Request $request): array
    {
        try {
            $payload = $this->otpService->verifyPasswordResetOtp($challengeToken, $otp);
        } catch (\Throwable $th) {
            GeneralHelper::storeAuditLog(UserTypeEnum::ADMIN, AuditActionEnum::OTP_FAILED, $request, null, [
                'purpose' => 'PASSWORD_RESET',
            ], 'Password reset OTP verification failed.', null, null, ModuleEnums::authentication, 422);

            throw $th;
        }

        GeneralHelper::storeAuditLog(UserTypeEnum::ADMIN, AuditActionEnum::OTP_VERIFIED, $request, null, [
            'purpose' => 'PASSWORD_RESET',
        ], 'Password reset OTP verified successfully.', null, null, ModuleEnums::authentication, 200);

        return $payload;
    }

    public function resetPassword(string $resetToken, string $password, Request $request): void
    {
        $payload = $this->decodeResetToken($resetToken);
        $subject = $this->resolveSubject($payload);

        $storedHash = $subject->getRawOriginal('password');
        if (is_string($storedHash) && $storedHash !== '' && Hash::check($password, $storedHash)) {
            throw new ApiException('Your new password must be different from your current password.', 422);
        }

        $updates = [
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
        ];
        if ($subject instanceof Admin) {
            $updates['must_reset_password'] = false;
        }
        $subject->forceFill($updates)->save();

        $subject->tokens()->delete();
        event(new PasswordReset($subject));

        $userType = $subject instanceof Admin ? UserTypeEnum::ADMIN : UserTypeEnum::CUSTOMER;
        GeneralHelper::storeAuditLog(
            $userType,
            AuditActionEnum::PASSWORD_RESET_COMPLETED,
            $request,
            $subject->uuid,
            [],
            $this->displayName($subject).' completed password reset.',
            $subject::class,
            $subject->uuid,
            ModuleEnums::authentication,
            200,
        );
    }

    public function issueResetTokenFor(User|Admin $subject): string
    {
        $expiresMinutes = (int) config('auth.passwords.'.($subject instanceof Admin ? 'admins' : 'users').'.expire', 60);
        $exp = now()->addMinutes(max(1, $expiresMinutes))->timestamp;

        return encrypt([
            'purpose' => 'RESET_PASSWORD',
            'exp' => $exp,
            'subject_type' => $subject instanceof Admin ? UserTypeEnum::ADMIN->value : UserTypeEnum::CUSTOMER->value,
            'subject_id' => $subject->uuid,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResetToken(string $resetToken): array
    {
        try {
            $payload = decrypt($resetToken);
        } catch (\Throwable) {
            throw new ApiException('Unable to reset password. Please restart the forgot password process.', 422);
        }

        if (! is_array($payload) || ($payload['purpose'] ?? null) !== 'RESET_PASSWORD') {
            throw new ApiException('Unable to reset password. Please restart the forgot password process.', 422);
        }

        if (now()->timestamp > (int) ($payload['exp'] ?? 0)) {
            throw new ApiException('Unable to reset password. Please restart the forgot password process.', 422);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSubject(array $payload): User|Admin
    {
        if (($payload['subject_type'] ?? null) === UserTypeEnum::ADMIN->value) {
            return Admin::query()->where('uuid', $payload['subject_id'] ?? null)->firstOrFail();
        }

        return User::query()->where('uuid', $payload['subject_id'] ?? null)->firstOrFail();
    }

    private function displayName(User|Admin $subject): string
    {
        if ($subject instanceof Admin) {
            return $subject->name;
        }

        return $subject->displayName();
    }

}
