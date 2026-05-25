<?php

namespace App\Services\Auth;

use App\Enums\AuditActionEnum;
use App\Enums\CustomerRegistrationStepEnum;
use App\Enums\DonorTypeSlug;
use App\Enums\OtpChannelEnum;
use App\Enums\eClientType;
use App\Enums\eRole;
use App\Enums\ModuleEnums;
use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Helpers\OpaqueMessageHelper;
use App\Jobs\LinkGuestDonorHistoryJob;
use App\Mail\WelcomeToEndowmentPortalMail;
use App\Models\Admin;
use App\Models\Country;
use App\Models\DonorType;
use App\Models\GraduationSet;
use App\Models\User;
use App\Notifications\GenericDatabaseNotification;
use App\Repositories\Contracts\User\UserRepositoryInterface;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Theme\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthService
{
    /**
     * Bcrypt hash used to reduce timing differences for non-existing accounts.
     */
    private const DUMMY_BCRYPT_FOR_TIMING = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly OtpService $otpService,
        private readonly NotificationDispatchService $notificationDispatchService,
    ) {}

    public function register(array $data)
    {
        $data['password'] = Hash::make($data['password']);

        return $this->userRepository->create($data);
    }

    /**
     * Register an ICOBA endowment donor and send an email verification OTP. Tokens are issued after email is verified.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function registerCustomer(array $validated, Request $request): array
    {
        $donorType = DonorType::query()->where('slug', $validated['donor_type'])->firstOrFail();
        $payload = $this->mapDonorRegistrationToUserPayload($validated, $donorType);
        $user = $this->userRepository->create($payload);
        $user->assignRole(eRole::CUSTOMER->value);
        $user->load(['roles', 'donorType', 'corporateCategory', 'graduationSet']);

        LinkGuestDonorHistoryJob::dispatch($user->uuid);

        GeneralHelper::storeAuditLog(
            UserTypeEnum::CUSTOMER,
            AuditActionEnum::REGISTERED,
            $request,
            $user->uuid,
            ['donor_type' => $donorType->slug],
            $this->displayName($user).' registered.',
            User::class,
            $user->uuid,
            ModuleEnums::authentication,
            201,
        );

        $channel = OtpChannelEnum::tryFromRequest($validated['otp_channel'] ?? null);
        $otpPayload = $this->otpService->sendEmailVerificationOtp($user, $channel);
        GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_SENT, $request, $user->uuid, [
            'purpose' => 'EMAIL_VERIFICATION',
            'otp_channel' => $channel->value,
            'reuse_active_challenge' => (bool) ($otpPayload['cooldown_active'] ?? false),
        ], $this->displayName($user).' was sent a verification code.', User::class, $user->uuid, ModuleEnums::authentication, 200);

        return $this->withRegistrationStep($otpPayload, CustomerRegistrationStepEnum::AWAITING_OTP);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mapDonorRegistrationToUserPayload(array $validated, DonorType $donorType): array
    {
        $row = [
            'email' => $validated['email'],
            'password' => $validated['password'],
            'phone_number' => $validated['phone_number'],
            'country_code' => $validated['country_code'] ?? Country::defaultDialCode(),
            'donor_type_uuid' => $donorType->uuid,
            'corporate_category_uuid' => null,
            'graduation_set_uuid' => null,
            'organization_name' => null,
            'rc_number' => null,
            'tin' => null,
            'alumni_identifier' => null,
        ];

        return match ($donorType->slug) {
            DonorTypeSlug::ICOBA_ALUMNI->value => array_merge($row, [
                'firstname' => $validated['firstname'],
                'lastname' => $validated['lastname'],
                'graduation_set_uuid' => GraduationSet::query()->where('set_number', $validated['set_number'])->value('uuid'),
                'alumni_identifier' => ! empty($validated['alumni_identifier']) ? $validated['alumni_identifier'] : null,
            ]),
            DonorTypeSlug::CORPORATE_DONOR->value => array_merge($row, [
                'organization_name' => $validated['organization_name'],
                'corporate_category_uuid' => $validated['corporate_category_uuid'],
                'rc_number' => $validated['rc_number'],
                'tin' => $validated['tin'],
                'firstname' => $validated['organization_name'],
                'lastname' => '', //Organization
            ]),
            DonorTypeSlug::FRIENDS_OF_ICOBA->value, DonorTypeSlug::RELATIVES_OF_ICOBA->value => array_merge($row, [
                'firstname' => $validated['firstname'],
                'lastname' => $validated['lastname'],
            ]),
            default => $row,
        };
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

        if ($user->email_verified_at === null) {
            $payload = $this->otpService->sendEmailVerificationOtp($user);
            GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_SENT, $request, $user->uuid, [
                'purpose' => 'EMAIL_VERIFICATION',
                'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
            ], $this->displayName($user).' was sent an email verification code during login.', User::class, $user->uuid, ModuleEnums::authentication, 200);

            return $this->withRegistrationStep($payload, CustomerRegistrationStepEnum::AWAITING_OTP);
        }

        if (! $this->customerRequiresLoginOtp($user)) {
            $payload = $this->issueToken($user, $client, ['customer']);
            $user->forceFill(['last_login_at' => now()])->save();
            $this->sendLoginSuccessNotification($user, $request, $client, false);
            GeneralHelper::storeAuditLog(
                UserTypeEnum::CUSTOMER,
                AuditActionEnum::LOGIN_SUCCESS,
                $request,
                $user->uuid,
                ['two_factor' => false],
                $this->displayName($user).' logged in successfuly.',
                User::class,
                $user->uuid,
                ModuleEnums::authentication,
                200,
            );

            return $this->withRegistrationStep($payload, CustomerRegistrationStepEnum::COMPLETED);
        }

        $payload = $this->otpService->sendLoginOtp($user);
        GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_SENT, $request, $user->uuid, [
            'purpose' => 'LOGIN',
            'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
        ], $this->displayName($user).' requested a login OTP.', User::class, $user->uuid, ModuleEnums::authentication, 200);

        return $this->withLoginTwoFactor(
            $this->withRegistrationStep($payload, CustomerRegistrationStepEnum::COMPLETED)
        );
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

        if ((bool) $admin->must_reset_password) {
            throw new ApiException(
                'Password reset is required before you can continue.',
                403,
                ['must_reset_password' => true]
            );
        }

        if (! $this->adminRequiresLoginOtp($admin)) {
            $payload = $this->issueToken($admin, $client, ['admin']);
            $admin->forceFill(['last_login_at' => now()])->save();
            $this->sendLoginSuccessNotification($admin, $request, $client, false);
            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::LOGIN_SUCCESS,
                $request,
                $admin->uuid,
                ['two_factor' => false],
                $this->displayName($admin).' logged in successfuly.',
                Admin::class,
                $admin->uuid,
                ModuleEnums::authentication,
                200,
            );

            return $payload;
        }

        $payload = $this->otpService->sendLoginOtp($admin);
        GeneralHelper::storeAuditLog(UserTypeEnum::ADMIN, AuditActionEnum::OTP_SENT, $request, $admin->uuid, [
            'purpose' => 'LOGIN',
            'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
        ], $this->displayName($admin).' requested a login OTP.', Admin::class, $admin->uuid, ModuleEnums::authentication, 200);

        return $this->withLoginTwoFactor($payload);
    }

    public function verifyCustomerLoginOtp(string $challengeToken, string $otp, Request $request, string $client = eClientType::MOBILE->value): array
    {
        try {
            $user = $this->otpService->verifyLoginOtp($challengeToken, $otp);
        } catch (\Throwable $th) {
            GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_FAILED, $request, null, [
                'purpose' => 'LOGIN',
            ], 'Customer login OTP verification failed.', null, null, ModuleEnums::authentication, 422);

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

        $payload = $this->withRegistrationStep(
            $this->issueToken($user, $client, ['customer']),
            CustomerRegistrationStepEnum::COMPLETED
        );

        $user->forceFill(['last_login_at' => now()])->save();
        $this->sendLoginSuccessNotification($user, $request, $client, true);
        GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_VERIFIED, $request, $user->uuid, [
            'purpose' => 'LOGIN',
        ], $this->displayName($user).' verified login OTP successfully.', User::class, $user->uuid, ModuleEnums::authentication, 200);
        GeneralHelper::storeAuditLog(
            UserTypeEnum::CUSTOMER,
            AuditActionEnum::LOGIN_SUCCESS,
            $request,
            $user->uuid,
            [],
            $this->displayName($user).' logged in successfully.',
            User::class,
            $user->uuid,
            ModuleEnums::authentication,
            200,
        );

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyCustomerEmailOtp(string $challengeToken, string $otp, Request $request, string $client = eClientType::MOBILE->value): array
    {
        try {
            $user = $this->otpService->verifyEmailVerificationOtp($challengeToken, $otp);
        } catch (\Throwable $th) {
            GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_FAILED, $request, null, [
                'purpose' => 'EMAIL_VERIFICATION',
            ], 'Customer email verification OTP failed.', null, null, ModuleEnums::authentication, 422);

            $this->maybeOpaqueLoginOtpException($th);
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_VERIFIED, $request, $user->uuid, [
            'purpose' => 'EMAIL_VERIFICATION',
        ], $this->displayName($user).' verified their email.', User::class, $user->uuid, ModuleEnums::authentication, 200);
        GeneralHelper::storeAuditLog(
            UserTypeEnum::CUSTOMER,
            AuditActionEnum::EMAIL_VERIFIED,
            $request,
            $user->uuid,
            [],
            $this->displayName($user).' email verified.',
            User::class,
            $user->uuid,
            ModuleEnums::authentication,
            200,
        );

        $this->sendEndowmentWelcomeMail($user);

        if ($this->customerRequiresLoginOtp($user)) {
            $payload = $this->otpService->sendLoginOtp($user);
            GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_SENT, $request, $user->uuid, [
                'purpose' => 'LOGIN',
                'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
            ], $this->displayName($user).' was sent a login OTP after email verification.', User::class, $user->uuid, ModuleEnums::authentication, 200);

            return $this->withLoginTwoFactor(
                $this->withRegistrationStep($payload, CustomerRegistrationStepEnum::COMPLETED)
            );
        }

        $payload = $this->withRegistrationStep(
            $this->issueToken($user, $client, ['customer']),
            CustomerRegistrationStepEnum::COMPLETED
        );
        $user->forceFill(['last_login_at' => now()])->save();
        $this->sendLoginSuccessNotification($user, $request, $client, false);
        GeneralHelper::storeAuditLog(
            UserTypeEnum::CUSTOMER,
            AuditActionEnum::LOGIN_SUCCESS,
            $request,
            $user->uuid,
            ['after_email_verification' => true],
            $this->displayName($user).' logged in after email verification.',
            User::class,
            $user->uuid,
            ModuleEnums::authentication,
            200,
        );

        return $payload;
    }

    public function resendCustomerEmailVerificationOtp(string $challengeToken, ?OtpChannelEnum $channel, Request $request): array
    {
        $payload = $this->otpService->resendEmailVerificationOtp($challengeToken, $channel);
        GeneralHelper::storeAuditLog(UserTypeEnum::CUSTOMER, AuditActionEnum::OTP_SENT, $request, null, [
            'purpose' => 'EMAIL_VERIFICATION',
            'otp_channel' => $payload['otp_channel'] ?? $channel?->value,
            'resend' => true,
            'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
        ], 'Customer verification OTP was resent.', null, null, ModuleEnums::authentication, 200);

        return $this->withRegistrationStep($payload, CustomerRegistrationStepEnum::AWAITING_OTP);
    }

    public function verifyAdminLoginOtp(string $challengeToken, string $otp, Request $request, string $client = eClientType::WEB->value): array
    {
        try {
            $admin = $this->otpService->verifyLoginOtp($challengeToken, $otp);
        } catch (\Throwable $th) {
            GeneralHelper::storeAuditLog(UserTypeEnum::ADMIN, AuditActionEnum::OTP_FAILED, $request, null, [
                'purpose' => 'LOGIN',
            ], 'Admin login OTP verification failed.', null, null, ModuleEnums::authentication, 422);

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

        if ((bool) $admin->must_reset_password) {
            throw new ApiException(
                'Password reset is required before you can continue.',
                403,
                ['must_reset_password' => true]
            );
        }

        $payload = $this->issueToken($admin, $client, ['admin']);

        $admin->forceFill(['last_login_at' => now()])->save();
        $this->sendLoginSuccessNotification($admin, $request, $client, true);
        GeneralHelper::storeAuditLog(UserTypeEnum::ADMIN, AuditActionEnum::OTP_VERIFIED, $request, $admin->uuid, [
            'purpose' => 'LOGIN',
        ], $this->displayName($admin).' verified login OTP successfully.', Admin::class, $admin->uuid, ModuleEnums::authentication, 200);
        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::LOGIN_SUCCESS,
            $request,
            $admin->uuid,
            [],
            $this->displayName($admin).' logged in successfully.',
            Admin::class,
            $admin->uuid,
            ModuleEnums::authentication,
            200,
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
        ], 'Customer login OTP was resent.', null, null, ModuleEnums::authentication, 200);

        return $this->withLoginTwoFactor(
            $this->withRegistrationStep($payload, CustomerRegistrationStepEnum::COMPLETED)
        );
    }

    public function resendAdminLoginOtp(string $challengeToken, Request $request): array
    {
        $payload = $this->otpService->resendLoginOtp($challengeToken);
        GeneralHelper::storeAuditLog(UserTypeEnum::ADMIN, AuditActionEnum::OTP_SENT, $request, null, [
            'purpose' => 'LOGIN',
            'resend' => true,
            'reuse_active_challenge' => (bool) ($payload['cooldown_active'] ?? false),
        ], 'Admin login OTP was resent.', null, null, ModuleEnums::authentication, 200);

        return $this->withLoginTwoFactor($payload);
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withRegistrationStep(array $payload, CustomerRegistrationStepEnum $step): array
    {
        return array_merge($payload, [
            'registration_step' => $step->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withLoginTwoFactor(array $payload): array
    {
        return array_merge($payload, [
            '2fa' => true,
        ]);
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
            ModuleEnums::authentication,
            400,
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

        return $authenticatable->displayName();
    }

    private function sendLoginSuccessNotification(User|Admin $authenticatable, Request $request, string $client, bool $viaOtp): void
    {
        $notification = new GenericDatabaseNotification(
            module: ModuleEnums::authentication->value,
            event: 'login_success',
            title: 'Successful login',
            message: 'Your account just logged in successfully.',
            meta: [
                'via_otp' => $viaOtp,
                'client' => $client,
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'logged_in_at' => now()->toIso8601String(),
            ],
            actionUrl: null,
            mailSubject: null,
            icon: '/icons/login-success.png',
            severity: 'info',
            tags: ['auth', 'login'],
            sendMail: false,
            sendPush: false,
        );

        if ($authenticatable instanceof Admin) {
            $this->notificationDispatchService->notifyAdminsByUuids([$authenticatable->uuid], $notification);

            return;
        }

        $this->notificationDispatchService->notifyUsersByUuids([$authenticatable->uuid], $notification);
    }

    private function sendEndowmentWelcomeMail(User $user): void
    {
        $loginUrl = config('app.frontend_login_url');

        if (! is_string($loginUrl) || $loginUrl === '') {
            $loginUrl = rtrim((string) config('app.frontend_url'), '/').'/login';
        }

        try {
            $theme = app(ThemeResolver::class)->resolveForMail();

            Mail::to($user->email)->send(new WelcomeToEndowmentPortalMail(
                $this->displayName($user),
                $theme,
                $loginUrl
            ));
        } catch (\Throwable $e) {
            Log::warning('Welcome email failed after email verification.', [
                'user_uuid' => $user->uuid,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
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
