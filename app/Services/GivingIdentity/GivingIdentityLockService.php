<?php

namespace App\Services\GivingIdentity;

use App\Enums\GivingIdentityStatus;
use App\Models\GivingIdentity;
use App\Models\Transaction;
use App\Models\User;

final class GivingIdentityLockService
{
    public function lockFromSuccessfulTransaction(Transaction $transaction): void
    {
        if ($transaction->giving_identity_uuid === null) {
            return;
        }

        $identity = GivingIdentity::query()
            ->where('uuid', $transaction->giving_identity_uuid)
            ->lockForUpdate()
            ->first();

        if ($identity === null || $identity->locked_at !== null) {
            return;
        }

        $identity->forceFill([
            'status' => GivingIdentityStatus::ACTIVE,
            'locked_at' => now(),
        ])->save();
    }

    public function isLockedForUser(User $user): bool
    {
        return GivingIdentity::query()
            ->where('user_uuid', $user->uuid)
            ->whereNotNull('locked_at')
            ->exists();
    }

    public function findForUser(User $user): ?GivingIdentity
    {
        return GivingIdentity::query()
            ->where('user_uuid', $user->uuid)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function lockedProfileFieldsBeingChanged(User $user, array $data): array
    {
        $identity = $this->findForUser($user);
        if ($identity === null || ! $identity->isLocked()) {
            return [];
        }

        $user->loadMissing('donorType');
        $slug = $user->donorType?->slug;
        $blocked = [];

        if ($slug === \App\Enums\DonorTypeSlug::ICOBA_ALUMNI->value) {
            if (array_key_exists('set_number', $data)) {
                $newSetUuid = \App\Models\GraduationSet::query()
                    ->where('set_number', (string) $data['set_number'])
                    ->value('uuid');
                if (! GivingIdentityNormalizer::compareUuid($newSetUuid, $identity->graduation_set_uuid)) {
                    $blocked[] = 'set_number';
                }
            }
            foreach (['firstname', 'lastname'] as $field) {
                if (array_key_exists($field, $data) && ! GivingIdentityNormalizer::compareText($data[$field], $identity->{$field})) {
                    $blocked[] = $field;
                }
            }
        } elseif ($slug === \App\Enums\DonorTypeSlug::CORPORATE_DONOR->value) {
            foreach (['organization_name', 'corporate_category_uuid'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $incoming = $field === 'organization_name'
                    ? GivingIdentityNormalizer::text((string) $data[$field])
                    : $data[$field];
                $current = $identity->{$field};
                $matches = $field === 'organization_name'
                    ? GivingIdentityNormalizer::compareText($incoming, $current)
                    : GivingIdentityNormalizer::compareUuid($incoming, $current);
                if (! $matches) {
                    $blocked[] = $field;
                }
            }
        } elseif (in_array($slug, [\App\Enums\DonorTypeSlug::FRIENDS_OF_ICOBA->value, \App\Enums\DonorTypeSlug::RELATIVES_OF_ICOBA->value, \App\Enums\DonorTypeSlug::WIVES_OF_ICOBA->value], true)) {
            foreach (['firstname', 'lastname'] as $field) {
                if (array_key_exists($field, $data) && ! GivingIdentityNormalizer::compareText($data[$field], $identity->{$field})) {
                    $blocked[] = $field;
                }
            }
        }

        return $blocked;
    }
}
