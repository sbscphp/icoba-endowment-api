<?php

namespace App\Services\Auth;

use App\Enums\AuditActionEnum;
use App\Enums\AuditModuleEnum;
use App\Enums\eClientType;
use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Helpers\OpaqueMessageHelper;
use App\Models\Admin;
use App\Models\User;
use App\Repositories\Contracts\User\UserRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * Bcrypt hash used to reduce timing differences for non-existing accounts.
     */
    private const DUMMY_BCRYPT_FOR_TIMING = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly OtpService $otpService,
    ) {}

    public function register(array $data)
    {
        $data['password'] = Hash::make($data['password']);

        return $this->userRepository->create($data);
    }

    public function loginCustomer(string $email, string $password, Request $request, string $client = eClientType::MOBILE->value): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user) {
            // Keep the no-user branch close to a real password check to reduce enumeration by timing.
            Hash::check($password, self::DUMMY_BCRYPT_FOR_TIMING);
            $this->failLogin(UserTypeEnum::CUSTOMER, $request, null, ['email' => $email]);
        }

        $this->autoUnlockIfExpired($user);

        if ($this->isLocked($user)) {
            $this->failLogin(UserTypeEnum::CUSTOMER, $request, $user->uuid, [
                'reason' => 'account_locked',
            ]);
        }

        $passwordMatches = is_string($user->password) && Hash::check($password, $user->password);

        if (! $user->is_active || ! $user->can_login || ! $passwordMatches) {
            if (! $passwordMatches) {
                $this->recordFailedLoginAttempt($user);
            }

            $this->failLogin(UserTypeEnum::CUSTOMER, $request, $user->uuid, ['email' => $email]);
        }

        $this->resetLoginLockState($user);

        if (! $this->customerRequiresLoginOtp($user)) {
            $payload = $this->issueToken($user, $client, ['customer']);
            $user->forceFill(['last_login_at' => now()])->save();
            GeneralHelper::storeAuditLog(
                UserTypeEnum::CUSTOMER,
                AuditActionEnum::LOGIN_SUCCESS,
                $request,
                $user->uuid,
                ['two_factor' => false],
                $this->displayName($user).' logged in successfuly.',
                User::class,
                $user->uuid,
                AuditModuleEnum::AUTHENTICATION
            );

            return $payload;
        }

        $payload = $this->otpService->sendLoginOtp($user);
        GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_SENT, $request, $user->uuid, [
            'purpose' => 'LOGIN',
            'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
        ], $this->displayName($user).' requested a login OTP.', User::class, $user->uuid, AuditModuleEnum::AUTHENTICATION);

        return $payload;
    }

    public function loginAdmin(string $email, string $password, Request $request, string $client = eClientType::WEB->value): array
    {
        $admin = Admin::query()->where('email', $email)->first();

        if (! $admin) {
            // Keep the no-admin branch close to a real password check to reduce enumeration by timing.
            Hash::check($password, self::DUMMY_BCRYPT_FOR_TIMING);
            $this->failLogin(UserTypeEnum::ADMIN, $request, null, ['email' => $email]);
        }

        $this->autoUnlockIfExpired($admin);

        if ($this->isLocked($admin)) {
            $this->failLogin(UserTypeEnum::ADMIN, $request, $admin->uuid, [
                'reason' => 'account_locked',
            ]);
        }

        $passwordMatches = is_string($admin->password) && Hash::check($password, $admin->password);

        if (! $admin->is_active || ! $admin->can_login || ! $passwordMatches) {
            if (! $passwordMatches) {
                $this->recordFailedLoginAttempt($admin);
            }

            $this->failLogin(UserTypeEnum::ADMIN, $request, $admin->uuid, ['email' => $email]);
        }

        $this->resetLoginLockState($admin);

        if (! $this->adminRequiresLoginOtp($admin)) {
            $payload = $this->issueToken($admin, $client, ['admin']);
            $admin->forceFill(['last_login_at' => now()])->save();
            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::LOGIN_SUCCESS,
                $request,
                $admin->uuid,
                ['two_factor' => false],
                $this->displayName($admin).' logged in successfuly.',
                Admin::class,
                $admin->uuid,
                AuditModuleEnum::AUTHENTICATION
            );

            return $payload;
        }

        $payload = $this->otpService->sendLoginOtp($admin);
        GeneralHelper::storeAuditLog(UserTypeEnum::ADMIN, AuditActionEnum::OTP_SENT, $request, $admin->uuid, [
            'purpose' => 'LOGIN',
            'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
        ], $this->displayName($admin).' requested a login OTP.', Admin::class, $admin->uuid, AuditModuleEnum::AUTHENTICATION);

        return $payload;
    }

    public function verifyCustomerLoginOtp(string $challengeToken, string $otp, Request $request, string $client = eClientType::MOBILE->value): array
    {
        try {
            $user = $this->otpService->verifyLoginOtp($challengeToken, $otp);
        } catch (\Throwable $th) {
            GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_FAILED, $request, null, [
                'purpose' => 'LOGIN',
            ], 'Customer login OTP verification failed.', null, null, AuditModuleEnum::AUTHENTICATION);

            $this->maybeOpaqueLoginOtpException($th);
        }

        if (! $user instanceof User) {
            throw new ApiException(
                OpaqueMessageHelper::authOpaqueEnabled('login')
                    ? OpaqueMessageHelper::MESSAGE_GENERIC_LOGIN_OTP_VERIFY_FAILURE
                    : 'Invalid or expired verification code.',
                422
            );
        }

        $payload = $this->issueToken($user, $client, ['customer']);

        $user->forceFill(['last_login_at' => now()])->save();
        GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_VERIFIED, $request, $user->uuid, [
            'purpose' => 'LOGIN',
        ], $this->displayName($user).' verified login OTP successfully.', User::class, $user->uuid, AuditModuleEnum::AUTHENTICATION);
        GeneralHelper::storeAuditLog(
            UserTypeEnum::CUSTOMER,
            AuditActionEnum::LOGIN_SUCCESS,
            $request,
            $user->uuid,
            [],
            $this->displayName($user).' logged in successfully.',
            User::class,
            $user->uuid,
            AuditModuleEnum::AUTHENTICATION
        );

        return $payload;
    }

    public function verifyAdminLoginOtp(string $challengeToken, string $otp, Request $request, string $client = eClientType::WEB->value): array
    {
        try {
            $admin = $this->otpService->verifyLoginOtp($challengeToken, $otp);
        } catch (\Throwable $th) {
            GeneralHelper::storeAuditLog(UserTypeEnum::ADMIN, AuditActionEnum::OTP_FAILED, $request, null, [
                'purpose' => 'LOGIN',
            ], 'Admin login OTP verification failed.', null, null, AuditModuleEnum::AUTHENTICATION);

            $this->maybeOpaqueLoginOtpException($th);
        }

        if (! $admin instanceof Admin) {
            throw new ApiException(
                OpaqueMessageHelper::authOpaqueEnabled('login')
                    ? OpaqueMessageHelper::MESSAGE_GENERIC_LOGIN_OTP_VERIFY_FAILURE
                    : 'Invalid or expired verification code.',
                422
            );
        }

        $payload = $this->issueToken($admin, $client, ['admin']);

        $admin->forceFill(['last_login_at' => now()])->save();
        GeneralHelper::storeAuditLog(UserTypeEnum::ADMIN, AuditActionEnum::OTP_VERIFIED, $request, $admin->uuid, [
            'purpose' => 'LOGIN',
        ], $this->displayName($admin).' verified login OTP successfully.', Admin::class, $admin->uuid, AuditModuleEnum::AUTHENTICATION);
        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::LOGIN_SUCCESS,
            $request,
            $admin->uuid,
            [],
            $this->displayName($admin).' logged in successfully.',
            Admin::class,
            $admin->uuid,
            AuditModuleEnum::AUTHENTICATION
        );

        return $payload;
    }

    public function resendCustomerLoginOtp(string $challengeToken, Request $request): array
    {
        $payload = $this->otpService->resendLoginOtp($challengeToken);
        GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_SENT, $request, null, [
            'purpose' => 'LOGIN',
            'resend' => true,
            'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
        ], 'Customer login OTP was resent.', null, null, AuditModuleEnum::AUTHENTICATION);

        return $payload;
    }

    public function resendAdminLoginOtp(string $challengeToken, Request $request): array
    {
        $payload = $this->otpService->resendLoginOtp($challengeToken);
        GeneralHelper::storeAuditLog(UserTypeEnum::ADMIN, AuditActionEnum::OTP_SENT, $request, null, [
            'purpose' => 'LOGIN',
            'resend' => true,
            'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
        ], 'Admin login OTP was resent.', null, null, AuditModuleEnum::AUTHENTICATION);

        return $payload;
    }

    public function logout($user): void
    {
        $user?->currentAccessToken()?->delete();
    }

    /**
     * @param  array<int, string>  $abilities
     * @return array<string, mixed>
     */
    private function issueToken(User|Admin $authenticatable, string $client, array $abilities): array
    {
        $authenticatable->tokens()->where('name', $client)->delete();

        return [
            'access_token' => $authenticatable->createToken($client, $abilities)->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $authenticatable,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws ApiException
     */
    private function failLogin(UserTypeEnum $userType, Request $request, ?string $userId, array $metadata = []): never
    {
        GeneralHelper::storeAuditLog(
            $userType,
            AuditActionEnum::LOGIN_FAILED,
            $request,
            $userId,
            $metadata,
            $userType->value.' login failed.',
            $this->modelClassForUserType($userType),
            $userId,
            AuditModuleEnum::AUTHENTICATION
        );

        throw new ApiException(
            OpaqueMessageHelper::authOpaqueEnabled('login')
                ? OpaqueMessageHelper::MESSAGE_GENERIC_LOGIN_FAILURE
                : 'Invalid credentials.',
            400
        );
    }

    /**
     * When opaque login errors are enabled, collapse OTP/challenge failures to a single outward message.
     *
     * @throws ApiException
     */
    private function maybeOpaqueLoginOtpException(\Throwable $th): never
    {
        if (! OpaqueMessageHelper::authOpaqueEnabled('login')) {
            throw $th;
        }

        $status = $th instanceof ApiException ? $th->status : 422;

        throw new ApiException(OpaqueMessageHelper::MESSAGE_GENERIC_LOGIN_OTP_VERIFY_FAILURE, $status);
    }

    private function autoUnlockIfExpired(User|Admin $authenticatable): void
    {
        if (! $this->loginLockEnabled() || ! $authenticatable->is_locked || ! $authenticatable->locked_at) {
            return;
        }

        if (now()->diffInMinutes($authenticatable->locked_at) < $this->lockMinutes()) {
            return;
        }

        $this->resetLoginLockState($authenticatable);
    }

    private function isLocked(User|Admin $authenticatable): bool
    {
        return $this->loginLockEnabled() && (bool) $authenticatable->is_locked;
    }

    private function recordFailedLoginAttempt(User|Admin $authenticatable): void
    {
        if (! $this->loginLockEnabled()) {
            return;
        }

        $attempts = min(255, (int) $authenticatable->login_attempts + 1);
        $updates = ['login_attempts' => $attempts];

        if ($attempts >= $this->lockAttempts()) {
            $updates['is_locked'] = true;
            $updates['locked_at'] = now();
        }

        $authenticatable->forceFill($updates)->save();
    }

    private function resetLoginLockState(User|Admin $authenticatable): void
    {
        if (! $this->loginLockEnabled()) {
            return;
        }

        if ((int) $authenticatable->login_attempts === 0 && ! $authenticatable->is_locked && $authenticatable->locked_at === null) {
            return;
        }

        $authenticatable->forceFill([
            'login_attempts' => 0,
            'is_locked' => false,
            'locked_at' => null,
        ])->save();
    }

    private function loginLockEnabled(): bool
    {
        return (bool) config('security.login_lock_enabled', false);
    }

    private function lockAttempts(): int
    {
        return max(1, (int) config('security.login_lock_attempts', 3));
    }

    private function lockMinutes(): int
    {
        return max(1, (int) config('security.login_lock_minutes', 1440));
    }

    private function displayName(User|Admin $authenticatable): string
    {
        if ($authenticatable instanceof Admin) {
            return $authenticatable->name;
        }

        return trim($authenticatable->firstname.' '.$authenticatable->lastname) ?: $authenticatable->email;
    }

    private function modelClassForUserType(UserTypeEnum $userType): string
    {
        return $userType === UserTypeEnum::ADMIN ? Admin::class : User::class;
    }

    private function customerRequiresLoginOtp(User $user): bool
    {
        return (bool) $user->{'2fa'};
    }

    private function adminRequiresLoginOtp(Admin $admin): bool
    {
        return (bool) $admin->{'2fa'};
    }
}
