<?php

namespace App\Services\GivingIdentity;

use App\Enums\GivingIdentitySource;
use App\Enums\GivingIdentityStatus;
use App\Models\GivingIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GivingIdentityResolver
{
    /**
     * Resolve or create a giving identity for a registered user.
     */
    public function resolveForUser(User $user, GivingIdentitySource $source = GivingIdentitySource::GUEST_CHECKOUT): GivingIdentity
    {
        $user->loadMissing(['donorType', 'graduationSet', 'corporateCategory']);
        $profile = GivingIdentityProfileBuilder::fromUser($user);
        $emailLower = GivingIdentityNormalizer::email($user->email);

        return DB::transaction(function () use ($user, $profile, $emailLower, $source): GivingIdentity {
            $byUser = GivingIdentity::query()
                ->where('user_uuid', $user->uuid)
                ->lockForUpdate()
                ->first();

            if ($byUser !== null) {
                $this->assertProfileAllowedForIdentity($profile, $byUser);

                return $byUser;
            }

            if ($emailLower !== null) {
                $byEmail = GivingIdentity::query()
                    ->where('email_lower', $emailLower)
                    ->lockForUpdate()
                    ->first();

                if ($byEmail !== null) {
                    if ($byEmail->user_uuid !== null && $byEmail->user_uuid !== $user->uuid) {
                        throw ValidationException::withMessages([
                            'email' => ['This email is already linked to another account.'],
                        ]);
                    }

                    if (! $profile->hardFieldsMatchIdentity($byEmail)) {
                        if ($byEmail->isLocked()) {
                            throw ValidationException::withMessages([
                                'email' => [$this->conflictMessage($byEmail)],
                            ]);
                        }

                        $byEmail->forceFill(array_merge(
                            $profile->toIdentityAttributes($emailLower),
                            ['user_uuid' => $user->uuid, 'status' => GivingIdentityStatus::ACTIVE],
                        ))->save();

                        return $byEmail->refresh();
                    }

                    if ($byEmail->user_uuid === null) {
                        $byEmail->forceFill(['user_uuid' => $user->uuid])->save();
                    }

                    $this->mergeSoftFields($byEmail, $profile);

                    return $byEmail->refresh();
                }
            }

            return GivingIdentity::query()->create(array_merge(
                $profile->toIdentityAttributes($emailLower),
                [
                    'user_uuid' => $user->uuid,
                    'status' => GivingIdentityStatus::ACTIVE,
                    'source' => $source,
                ],
            ));
        });
    }

    /**
     * Resolve or create a giving identity for a guest donor.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $guestSnapshot
     */
    public function resolveForGuest(
        array $data,
        ?array $guestSnapshot = null,
        GivingIdentitySource $source = GivingIdentitySource::GUEST_CHECKOUT,
    ): GivingIdentity {
        $emailLower = GivingIdentityNormalizer::email($data['donor_email'] ?? null);

        if ($emailLower === null) {
            throw ValidationException::withMessages([
                'donor_email' => ['Donor email is required to establish a giving identity.'],
            ]);
        }

        $profile = GivingIdentityProfileBuilder::fromGuestPayload($data, $guestSnapshot);

        if ($profile->donorTypeUuid === null) {
            throw ValidationException::withMessages([
                'donor_type' => ['Donor type is required to establish a giving identity.'],
            ]);
        }

        return DB::transaction(function () use ($emailLower, $profile, $source): GivingIdentity {
            $existing = GivingIdentity::query()
                ->where('email_lower', $emailLower)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return GivingIdentity::query()->create(array_merge(
                    $profile->toIdentityAttributes($emailLower),
                    [
                        'status' => GivingIdentityStatus::UNVERIFIED,
                        'source' => $source,
                    ],
                ));
            }

            if ($existing->user_uuid !== null) {
                throw ValidationException::withMessages([
                    'donor_email' => [
                        'An account already exists for this email. Please log in to continue giving.',
                    ],
                ]);
            }

            if ($existing->status === GivingIdentityStatus::CONFLICT) {
                throw ValidationException::withMessages([
                    'donor_email' => [
                        'This email has conflicting giving history. Please contact ICOBA support for assistance.',
                    ],
                ]);
            }

            if (! $profile->hardFieldsMatchIdentity($existing)) {
                throw ValidationException::withMessages([
                    'donor_email' => [$this->conflictMessage($existing)],
                ]);
            }

            $this->mergeSoftFields($existing, $profile);

            return $existing->refresh();
        });
    }

    /**
     * Link a newly registered user to any existing guest identity with the same email.
     *
     * @throws ValidationException when a locked identity conflicts with the registration profile
     */
    public function linkRegistrationToIdentity(User $user, GivingIdentitySource $source = GivingIdentitySource::REGISTRATION): GivingIdentity
    {
        $user->loadMissing(['donorType', 'graduationSet', 'corporateCategory']);
        $profile = GivingIdentityProfileBuilder::fromUser($user);
        $emailLower = GivingIdentityNormalizer::email($user->email);

        if ($emailLower === null) {
            return $this->resolveForUser($user, $source);
        }

        return DB::transaction(function () use ($user, $profile, $emailLower, $source): GivingIdentity {
            $existing = GivingIdentity::query()
                ->where('email_lower', $emailLower)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return GivingIdentity::query()->create(array_merge(
                    $profile->toIdentityAttributes($emailLower),
                    [
                        'user_uuid' => $user->uuid,
                        'status' => GivingIdentityStatus::ACTIVE,
                        'source' => $source,
                    ],
                ));
            }

            if ($existing->user_uuid !== null && $existing->user_uuid !== $user->uuid) {
                throw ValidationException::withMessages([
                    'email' => ['This email is already linked to another account.'],
                ]);
            }

            if (! $profile->hardFieldsMatchIdentity($existing)) {
                if ($existing->isLocked()) {
                    $existing->forceFill(['status' => GivingIdentityStatus::CONFLICT])->save();

                    throw ValidationException::withMessages([
                        'email' => [
                            'This email already has giving history under a different donor profile. Please contact ICOBA support.',
                        ],
                    ]);
                }

                $existing->forceFill(array_merge(
                    $profile->toIdentityAttributes($emailLower),
                    [
                        'user_uuid' => $user->uuid,
                        'status' => GivingIdentityStatus::ACTIVE,
                        'source' => $source,
                    ],
                ))->save();

                return $existing->refresh();
            }

            $existing->forceFill([
                'user_uuid' => $user->uuid,
                'status' => GivingIdentityStatus::ACTIVE,
                'source' => $existing->source ?? $source,
            ])->save();

            $this->mergeSoftFields($existing, $profile);

            return $existing->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $pledgeData
     */
    public function resolveForPledgeData(array $pledgeData, ?User $user = null, GivingIdentitySource $source = GivingIdentitySource::PLEDGE): ?GivingIdentity
    {
        if ($user !== null) {
            return $this->resolveForUser($user, $source);
        }

        $emailLower = GivingIdentityNormalizer::email($pledgeData['donor_email'] ?? null);
        if ($emailLower === null) {
            return null;
        }

        $profile = GivingIdentityProfileBuilder::fromGuestPayload($pledgeData, [
            'donor_type_uuid' => $pledgeData['donor_type_uuid'] ?? null,
            'guest_donor_profile' => is_array($pledgeData['metadata']['guest_donor_profile'] ?? null)
                ? $pledgeData['metadata']['guest_donor_profile']
                : [],
        ]);

        if ($profile->donorTypeUuid === null) {
            return null;
        }

        return $this->resolveForGuest(
            array_merge($pledgeData, ['donor_email' => $emailLower]),
            [
                'donor_type_uuid' => $pledgeData['donor_type_uuid'] ?? null,
                'guest_donor_profile' => is_array($pledgeData['metadata']['guest_donor_profile'] ?? null)
                    ? $pledgeData['metadata']['guest_donor_profile']
                    : [],
            ],
            $source,
        );
    }

    private function assertProfileAllowedForIdentity(GivingIdentityProfile $profile, GivingIdentity $identity): void
    {
        if ($identity->isLocked() && ! $profile->hardFieldsMatchIdentity($identity)) {
            throw ValidationException::withMessages([
                'donor_email' => ['Your account giving profile cannot be changed after a successful donation. Contact support if you need help.'],
            ]);
        }
    }

    private function mergeSoftFields(GivingIdentity $identity, GivingIdentityProfile $profile): void
    {
        $updates = [];

        foreach (['rc_number', 'tin', 'alumni_identifier'] as $field) {
            $incoming = $profile->{$field === 'rc_number' ? 'rcNumber' : ($field === 'tin' ? 'tin' : 'alumniIdentifier')};
            $current = $identity->{$field};

            if (($current === null || $current === '') && filled($incoming)) {
                $updates[$field] = $incoming;
            }
        }

        if ($updates !== []) {
            $identity->forceFill($updates)->save();
        }
    }

    private function conflictMessage(GivingIdentity $existing): string
    {
        $existing->loadMissing(['donorType', 'graduationSet']);

        $profile = GivingIdentityProfileBuilder::fromIdentity($existing);
        $label = $profile->displayLabel();
        $typeLabel = $profile->donorTypeLabel();

        $details = $typeLabel;
        if ($existing->graduationSet !== null) {
            $details .= ', Set '.$existing->graduationSet->set_number;
        }

        return sprintf(
            'This email is already linked to %s (%s). To give under a different identity, use a different email or log in to your account.',
            $label,
            $details,
        );
    }
}
