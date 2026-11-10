<?php

namespace App\Services\Settings;

use App\Enums\AuditActionEnum;
use App\Enums\ModuleEnums;
use App\Enums\PledgeStatus;
use App\Enums\UserTypeEnum;
use App\Helpers\GeneralHelper;
use App\Mail\AccountDeletedMail;
use App\Models\AccountDeletion;
use App\Models\Pledge;
use App\Models\User;
use App\Services\Public\PublicEndowmentStatsService;
use App\Services\Theme\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Self-service account deletion for donors.
 *
 * The donation ledger is kept: transactions, pledges, receipts, recognitions and the giving identity
 * stay linked to the user uuid. The users row itself is closed and anonymised, a full snapshot of the
 * profile is written to account_deletions, every active pledge is cancelled, and all credentials,
 * tokens and notifications are revoked.
 */
final class CustomerAccountDeletionService
{
    public const ANONYMISED_FIRSTNAME = 'Deleted';

    public const ANONYMISED_LASTNAME = 'Donor';

    public const ANONYMISED_EMAIL_DOMAIN = 'deleted.invalid';

    public const PLEDGE_CANCELLED_REASON = 'account_deleted';

    public function __construct(
        private readonly ThemeResolver $themeResolver,
    ) {}

    public function delete(User $user, ?string $reason, Request $request): AccountDeletion
    {
        $user->loadMissing(['donorType', 'graduationSet', 'affiliatedGraduationSet', 'corporateCategory']);

        $reason = $this->normaliseReason($reason);
        $originalEmail = (string) $user->email;
        $displayName = $user->displayName();
        $profile = $this->snapshot($user);

        /** @var array{deletion: AccountDeletion, pledges_cancelled: int} $outcome */
        $outcome = DB::transaction(function () use ($user, $reason, $request, $originalEmail, $displayName, $profile): array {
            $pledgesCancelled = $this->cancelActivePledges($user);
            $revoked = $this->revokeAccess($user, $originalEmail);

            $summary = array_merge($revoked, [
                'pledges_cancelled' => $pledgesCancelled,
                'transactions_kept' => $user->transactions()->count(),
                'pledges_kept' => $user->pledges()->count(),
            ]);

            $deletion = AccountDeletion::query()->create([
                'user_uuid' => $user->uuid,
                'email' => $originalEmail,
                'display_name' => $displayName,
                'reason' => $reason,
                'profile' => $profile,
                'summary' => $summary,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $this->anonymise($user);

            return ['deletion' => $deletion, 'pledges_cancelled' => $pledgesCancelled];
        });

        $deletion = $outcome['deletion'];

        GeneralHelper::storeAuditLog(
            UserTypeEnum::CUSTOMER,
            AuditActionEnum::ACCOUNT_DELETED,
            $request,
            $user->uuid,
            [
                'account_deletion_uuid' => $deletion->uuid,
                'reason' => $reason,
                'email' => $originalEmail,
                'summary' => $deletion->summary,
                'profile' => $profile,
            ],
            $displayName.' deleted their account.',
            User::class,
            $user->uuid,
            ModuleEnums::settings,
            200,
        );

        $this->sendConfirmationEmail($originalEmail, $displayName, $outcome['pledges_cancelled'], $user->uuid);

        PublicEndowmentStatsService::forgetCache();

        return $deletion;
    }

    private function cancelActivePledges(User $user): int
    {
        $cancelled = 0;
        $now = now()->toIso8601String();

        Pledge::query()
            ->where('user_uuid', $user->uuid)
            ->where('status', PledgeStatus::ACTIVE)
            ->orderBy('id')
            ->chunkById(100, function ($pledges) use (&$cancelled, $now): void {
                foreach ($pledges as $pledge) {
                    if (! $pledge instanceof Pledge) {
                        continue;
                    }

                    $metadata = is_array($pledge->metadata) ? $pledge->metadata : [];
                    $metadata['cancelled_reason'] = self::PLEDGE_CANCELLED_REASON;
                    $metadata['cancelled_at'] = $now;

                    $pledge->forceFill([
                        'status' => PledgeStatus::CANCELLED,
                        'metadata' => $metadata,
                    ])->save();

                    $cancelled++;
                }
            });

        return $cancelled;
    }

    /**
     * @return array{tokens_revoked: int, otps_deleted: int, auth_challenges_deleted: int, sessions_deleted: int, password_reset_tokens_deleted: int, notifications_deleted: int}
     */
    private function revokeAccess(User $user, string $email): array
    {
        return [
            'tokens_revoked' => $user->tokens()->delete(),
            'otps_deleted' => DB::table('one_time_passwords')->where('user_id', $user->id)->delete(),
            'auth_challenges_deleted' => DB::table('auth_challenges')
                ->where('subject_type', UserTypeEnum::CUSTOMER->value)
                ->where('subject_id', $user->uuid)
                ->delete(),
            'sessions_deleted' => DB::table('sessions')->where('user_id', $user->id)->delete(),
            'password_reset_tokens_deleted' => DB::table('password_reset_tokens')->where('email', $email)->delete(),
            'notifications_deleted' => $user->notifications()->delete(),
        ];
    }

    private function anonymise(User $user): void
    {
        $user->forceFill([
            'firstname' => self::ANONYMISED_FIRSTNAME,
            'lastname' => self::ANONYMISED_LASTNAME,
            'middlename' => null,
            'email' => $this->anonymisedEmail($user),
            'phone_number' => null,
            'country_code' => null,
            'organization_name' => null,
            'rc_number' => null,
            'tin' => null,
            'alumni_identifier' => null,
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
            '2fa' => false,
            '2fa_expires_at' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'biometrics_enabled' => false,
            'email_notifications_enabled' => false,
            'push_notifications_enabled' => false,
            'stripe_customer_id' => null,
            'is_active' => false,
            'can_login' => false,
            'account_closed_at' => now(),
        ])->save();
    }

    public function anonymisedEmail(User $user): string
    {
        return 'deleted+'.$user->uuid.'@'.self::ANONYMISED_EMAIL_DOMAIN;
    }

    /**
     * Everything an admin may later need to know about who this donor was.
     *
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return [
            'uuid' => $user->uuid,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'middlename' => $user->middlename,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'phone_number' => $user->phone_number,
            'country_code' => $user->country_code,
            'donor_type' => $user->donorType?->only(['uuid', 'slug', 'label']),
            'graduation_set' => $user->graduationSet?->only(['uuid', 'name', 'set_number', 'public_id']),
            'house' => $user->house,
            'affiliated_graduation_set' => $user->affiliatedGraduationSet?->only(['uuid', 'name', 'set_number', 'public_id']),
            'wives_type' => $user->wives_type,
            'is_igbobian_owned' => (bool) $user->is_igbobian_owned,
            'alumni_identifier' => $user->alumni_identifier,
            'organization_name' => $user->organization_name,
            'rc_number' => $user->rc_number,
            'tin' => $user->tin,
            'corporate_category' => $user->corporateCategory?->only(['uuid', 'name']),
            'stripe_customer_id' => $user->stripe_customer_id,
            '2fa' => (bool) $user->{'2fa'},
            'biometrics_enabled' => (bool) $user->biometrics_enabled,
            'email_notifications_enabled' => (bool) $user->email_notifications_enabled,
            'push_notifications_enabled' => (bool) $user->push_notifications_enabled,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'last_active_at' => $user->last_active_at?->toIso8601String(),
            'registered_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function normaliseReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $reason = trim($reason);

        return $reason === '' ? null : $reason;
    }

    private function sendConfirmationEmail(string $email, string $displayName, int $pledgesCancelled, string $userUuid): void
    {
        if (trim($email) === '') {
            return;
        }

        try {
            $theme = $this->themeResolver->resolveForMail();

            Mail::to($email)->send(new AccountDeletedMail($displayName, $theme, $pledgesCancelled));
        } catch (\Throwable $e) {
            Log::warning('Account deleted email failed.', [
                'user_uuid' => $userUuid,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
