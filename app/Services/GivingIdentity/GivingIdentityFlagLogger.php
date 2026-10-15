<?php

namespace App\Services\GivingIdentity;

use App\Enums\GivingIdentitySource;
use App\Models\Admin;
use App\Models\CorporateCategory;
use App\Models\GivingIdentity;
use App\Models\GraduationSet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Writes one structured entry to the giving_identity log channel every time a donation or
 * registration is flagged against the identity already tied to an email: who tried to give,
 * which identity they collided with, and the field-by-field difference between the two profiles.
 */
final class GivingIdentityFlagLogger
{
    public const FLOW_REGISTRATION = 'registration';

    public const FLOW_USER_DONATION = 'user_donation';

    public const FLOW_GUEST_DONATION = 'guest_donation';

    /** Rejected: the identity is locked by a successful donation and the incoming profile differs. */
    public const OUTCOME_REJECTED_LOCKED_MISMATCH = 'rejected_locked_profile_mismatch';

    /** Rejected: guest checkout whose profile differs from the identity already on this email. */
    public const OUTCOME_REJECTED_PROFILE_MISMATCH = 'rejected_profile_mismatch';

    /** Rejected: the email's identity belongs to a different registered account. */
    public const OUTCOME_REJECTED_OTHER_ACCOUNT = 'rejected_email_linked_to_other_account';

    /** Rejected: guest checkout with an email that already has an account. */
    public const OUTCOME_REJECTED_ACCOUNT_REQUIRED = 'rejected_account_required';

    /** Rejected: the identity was previously marked conflict and needs support. */
    public const OUTCOME_REJECTED_IDENTITY_IN_CONFLICT = 'rejected_identity_in_conflict';

    /** Rejected and the identity was moved to conflict status. */
    public const OUTCOME_MARKED_CONFLICT = 'marked_conflict';

    /** Allowed: unlocked identity was overwritten with the incoming profile. */
    public const OUTCOME_IDENTITY_REPROFILED = 'identity_reprofiled';

    /**
     * @param  array<string, mixed>  $guest  Incoming guest payload (donor_email etc.) when no User is involved
     */
    public function log(
        string $flow,
        string $outcome,
        GivingIdentity $identity,
        GivingIdentityProfile $incoming,
        ?User $user = null,
        ?GivingIdentitySource $source = null,
        array $guest = [],
    ): void {
        try {
            $identity->loadMissing('donorType');

            $existing = GivingIdentityProfileBuilder::fromIdentity($identity);
            $differences = $this->describeDifferences($incoming->hardFieldDifferences($existing));

            $context = array_filter([
                'event' => 'giving_identity.flagged',
                'flow' => $flow,
                'outcome' => $outcome,
                'source' => $source?->value,
                'email' => $identity->email_lower,
                'user' => $user !== null ? $this->describeUser($user) : null,
                'guest' => $user === null ? $this->describeGuest($guest, $incoming) : null,
                'actor' => $this->describeActor($user),
                'identity' => [
                    'uuid' => $identity->uuid,
                    'status' => $identity->status?->value,
                    'source' => $identity->source?->value,
                    'user_uuid' => $identity->user_uuid,
                    'locked_at' => $identity->locked_at?->toIso8601String(),
                    'donor_type' => $existing->donorTypeSlug,
                    'label' => $existing->displayLabel(),
                ],
                'incoming' => [
                    'donor_type' => $incoming->donorTypeSlug,
                    'label' => $incoming->displayLabel(),
                ],
                'differences' => $differences,
                'request' => $this->describeRequest(),
            ], static fn ($value): bool => $value !== null);

            $level = $outcome === self::OUTCOME_IDENTITY_REPROFILED ? 'info' : 'warning';

            Log::channel('giving_identity')->{$level}(
                $this->headline($flow, $outcome, $identity, $existing, $incoming, $user, $differences),
                $context,
            );
        } catch (\Throwable $e) {
            // The flag log must never break a donation or registration.
            Log::warning('Giving identity flag log write failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * One readable sentence per flag: who tried, what they tried as, who already owns the email,
     * which identity fields differ, and what the system did about it.
     *
     * @param  array<string, array{existing: mixed, incoming: mixed}>  $differences
     */
    private function headline(
        string $flow,
        string $outcome,
        GivingIdentity $identity,
        GivingIdentityProfile $existing,
        GivingIdentityProfile $incoming,
        ?User $user,
        array $differences,
    ): string {
        $attempt = match ($flow) {
            self::FLOW_REGISTRATION => 'REGISTRATION',
            self::FLOW_GUEST_DONATION => 'GUEST DONATION',
            default => 'DONATION',
        };

        $verdict = $outcome === self::OUTCOME_IDENTITY_REPROFILED ? 'ALLOWED' : 'BLOCKED';

        $who = $user !== null
            ? sprintf('%s (registered user %s, %s)', $incoming->displayLabel(), $user->uuid, $user->email)
            : sprintf('guest %s', $incoming->displayLabel());

        $existingOwner = sprintf(
            '%s (%s%s, identity %s%s%s)',
            $existing->displayLabel(),
            $existing->donorTypeLabel(),
            $this->setSuffix($identity->graduation_set_uuid),
            $identity->uuid,
            $identity->user_uuid !== null ? ', has account '.$identity->user_uuid : ', no account',
            $identity->isLocked() ? ', locked by a successful donation' : ', not locked',
        );

        $reason = match ($outcome) {
            self::OUTCOME_MARKED_CONFLICT => 'profile does not match the locked identity; identity marked CONFLICT, user told to contact support',
            self::OUTCOME_REJECTED_LOCKED_MISMATCH => 'profile does not match the locked identity; user told to contact support or use another email',
            self::OUTCOME_REJECTED_PROFILE_MISMATCH => 'profile does not match the identity already on this email; guest told to use another email or log in',
            self::OUTCOME_REJECTED_OTHER_ACCOUNT => 'email is already linked to a different account',
            self::OUTCOME_REJECTED_ACCOUNT_REQUIRED => 'email already has an account; guest told to log in',
            self::OUTCOME_REJECTED_IDENTITY_IN_CONFLICT => 'identity is already in CONFLICT and needs support review',
            self::OUTCOME_IDENTITY_REPROFILED => 'identity was not locked, so it was overwritten with the new profile',
            default => $outcome,
        };

        return sprintf(
            '%s %s for %s | who: %s as %s%s | existing: %s | differs on: %s | why: %s',
            $attempt,
            $verdict,
            $identity->email_lower,
            $who,
            $incoming->donorTypeLabel(),
            $this->setSuffix($incoming->graduationSetUuid),
            $existingOwner,
            $this->differencesSentence($differences),
            $reason,
        );
    }

    /**
     * @param  array<string, array{existing: mixed, incoming: mixed}>  $differences
     */
    private function differencesSentence(array $differences): string
    {
        $labels = [
            'donor_type' => 'donor type',
            'firstname' => 'first name',
            'lastname' => 'last name',
            'graduation_set' => 'graduation set',
            'corporate_category' => 'corporate category',
            'organization_name' => 'organisation name',
        ];

        $parts = [];
        foreach ($labels as $field => $label) {
            if (! isset($differences[$field])) {
                continue;
            }

            $parts[] = sprintf(
                '%s "%s" -> "%s"',
                $label,
                $differences[$field]['existing'] ?? 'none',
                $differences[$field]['incoming'] ?? 'none',
            );
        }

        return $parts !== [] ? implode('; ', $parts) : 'nothing (profiles match)';
    }

    private function setSuffix(?string $graduationSetUuid): string
    {
        if ($graduationSetUuid === null) {
            return '';
        }

        $setNumber = GraduationSet::query()->where('uuid', $graduationSetUuid)->value('set_number');

        return $setNumber !== null ? ' Set '.$setNumber : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function describeUser(User $user): array
    {
        $user->loadMissing('donorType');

        return array_filter([
            'uuid' => $user->uuid,
            'email' => $user->email,
            'name' => trim(implode(' ', array_filter([$user->firstname, $user->lastname]))) ?: null,
            'donor_type' => $user->donorType?->slug,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $guest
     * @return array<string, mixed>|null
     */
    private function describeGuest(array $guest, GivingIdentityProfile $incoming): ?array
    {
        $email = $guest['donor_email'] ?? null;

        $details = array_filter([
            'email' => GivingIdentityNormalizer::email(is_string($email) ? $email : null),
            'name' => $incoming->displayLabel(),
            'donor_type' => $incoming->donorTypeSlug,
        ], static fn ($value): bool => $value !== null && $value !== '');

        return $details !== [] ? $details : null;
    }

    /**
     * Whoever is authenticated on this request when it is not the donor themself (e.g. an admin
     * creating a pledge or reconciling a payment on a donor's behalf).
     *
     * @return array<string, mixed>|null
     */
    private function describeActor(?User $user): ?array
    {
        $actor = Auth::user();

        if ($actor === null || ($user !== null && $actor instanceof User && $actor->uuid === $user->uuid)) {
            return null;
        }

        return array_filter([
            'type' => $actor instanceof Admin ? 'admin' : ($actor instanceof User ? 'customer' : class_basename($actor)),
            'uuid' => $actor->uuid ?? null,
            'email' => $actor->email ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function describeRequest(): ?array
    {
        if (app()->runningInConsole()) {
            return null;
        }

        $request = request();

        return array_filter([
            'ip' => $request->ip(),
            'method' => $request->method(),
            'path' => $request->path(),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Make opaque uuids readable by adding the set number / category name next to them.
     *
     * @param  array<string, array{existing: mixed, incoming: mixed}>  $differences
     * @return array<string, array{existing: mixed, incoming: mixed}>
     */
    private function describeDifferences(array $differences): array
    {
        if (isset($differences['graduation_set_uuid'])) {
            $differences['graduation_set'] = $this->lookup(
                GraduationSet::class,
                'set_number',
                $differences['graduation_set_uuid'],
            );
        }

        if (isset($differences['corporate_category_uuid'])) {
            $differences['corporate_category'] = $this->lookup(
                CorporateCategory::class,
                'name',
                $differences['corporate_category_uuid'],
            );
        }

        return $differences;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array{existing: mixed, incoming: mixed}  $pair
     * @return array{existing: mixed, incoming: mixed}
     */
    private function lookup(string $model, string $column, array $pair): array
    {
        $uuids = array_values(array_filter([$pair['existing'], $pair['incoming']], 'is_string'));
        $labels = $uuids === []
            ? []
            : $model::query()->whereIn('uuid', $uuids)->pluck($column, 'uuid')->all();

        return [
            'existing' => is_string($pair['existing']) ? ($labels[$pair['existing']] ?? null) : null,
            'incoming' => is_string($pair['incoming']) ? ($labels[$pair['incoming']] ?? null) : null,
        ];
    }
}
