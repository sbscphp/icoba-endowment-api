<?php

namespace App\Services\GivingIdentity;

use App\Enums\DonorTypeSlug;
use App\Models\GivingIdentity;

/**
 * Canonical donor profile used for identity comparison and persistence.
 */
final readonly class GivingIdentityProfile
{
    public function __construct(
        public ?string $donorTypeUuid,
        public ?string $donorTypeSlug,
        public ?string $graduationSetUuid = null,
        public ?string $corporateCategoryUuid = null,
        public ?string $organizationName = null,
        public ?string $rcNumber = null,
        public ?string $tin = null,
        public ?string $firstname = null,
        public ?string $lastname = null,
        public ?string $alumniIdentifier = null,
    ) {}

    public function hardFieldsMatch(GivingIdentityProfile $other): bool
    {
        if (! GivingIdentityNormalizer::compareUuid($this->donorTypeUuid, $other->donorTypeUuid)) {
            return false;
        }

        $slug = $this->donorTypeSlug ?? $other->donorTypeSlug;

        return match ($slug) {
            DonorTypeSlug::ICOBA_ALUMNI->value => GivingIdentityNormalizer::compareUuid(
                $this->graduationSetUuid,
                $other->graduationSetUuid,
            ) && GivingIdentityNormalizer::compareText($this->firstname, $other->firstname)
                && GivingIdentityNormalizer::compareText($this->lastname, $other->lastname),
            DonorTypeSlug::CORPORATE_DONOR->value => GivingIdentityNormalizer::compareUuid(
                $this->corporateCategoryUuid,
                $other->corporateCategoryUuid,
            ) && GivingIdentityNormalizer::compareText($this->organizationName, $other->organizationName),
            DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            DonorTypeSlug::RELATIVES_OF_ICOBA->value => GivingIdentityNormalizer::compareText($this->firstname, $other->firstname)
                && GivingIdentityNormalizer::compareText($this->lastname, $other->lastname),
            default => true,
        };
    }

    public function hardFieldsMatchIdentity(GivingIdentity $identity): bool
    {
        return $this->hardFieldsMatch(GivingIdentityProfileBuilder::fromIdentity($identity));
    }

    /**
     * @return array<string, mixed>
     */
    public function toIdentityAttributes(?string $emailLower = null): array
    {
        return array_filter([
            'email_lower' => $emailLower,
            'donor_type_uuid' => $this->donorTypeUuid,
            'graduation_set_uuid' => $this->graduationSetUuid,
            'corporate_category_uuid' => $this->corporateCategoryUuid,
            'organization_name' => GivingIdentityNormalizer::text($this->organizationName),
            'rc_number' => GivingIdentityNormalizer::text($this->rcNumber),
            'tin' => GivingIdentityNormalizer::text($this->tin),
            'firstname' => GivingIdentityNormalizer::text($this->firstname),
            'lastname' => GivingIdentityNormalizer::text($this->lastname),
            'alumni_identifier' => filled($this->alumniIdentifier) ? (string) $this->alumniIdentifier : null,
        ], fn ($value) => $value !== null);
    }

    public function displayLabel(): string
    {
        $slug = $this->donorTypeSlug;

        if ($slug === DonorTypeSlug::CORPORATE_DONOR->value) {
            return GivingIdentityNormalizer::text($this->organizationName) ?? 'Corporate Donor';
        }

        $name = trim(implode(' ', array_filter([
            GivingIdentityNormalizer::text($this->firstname),
            GivingIdentityNormalizer::text($this->lastname),
        ])));

        return $name !== '' ? $name : 'Donor';
    }

    public function donorTypeLabel(): string
    {
        if ($this->donorTypeSlug === null) {
            return 'Donor';
        }

        $slug = DonorTypeSlug::tryFrom($this->donorTypeSlug);

        return $slug?->label() ?? 'Donor';
    }
}
