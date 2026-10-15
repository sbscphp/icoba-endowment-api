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
        public ?string $house = null,
        public ?string $affiliatedGraduationSetUuid = null,
        public bool $isIgbobianOwned = false,
        public ?string $wivesType = null,
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
     * Hard fields on which this (incoming) profile disagrees with an existing one.
     * Mirrors hardFieldsMatch(): only the fields the donor type treats as identity-defining
     * are compared, so an empty result means the two profiles match.
     *
     * @return array<string, array{existing: mixed, incoming: mixed}>
     */
    public function hardFieldDifferences(GivingIdentityProfile $existing): array
    {
        $differences = [];

        if (! GivingIdentityNormalizer::compareUuid($this->donorTypeUuid, $existing->donorTypeUuid)) {
            $differences['donor_type_uuid'] = ['existing' => $existing->donorTypeUuid, 'incoming' => $this->donorTypeUuid];
            $differences['donor_type'] = ['existing' => $existing->donorTypeSlug, 'incoming' => $this->donorTypeSlug];
        }

        $slugs = array_unique(array_filter([$this->donorTypeSlug, $existing->donorTypeSlug]));
        $checks = [];

        foreach ($slugs as $slug) {
            $checks += match ($slug) {
                DonorTypeSlug::ICOBA_ALUMNI->value => [
                    'graduation_set_uuid' => 'uuid',
                    'firstname' => 'text',
                    'lastname' => 'text',
                ],
                DonorTypeSlug::CORPORATE_DONOR->value => [
                    'corporate_category_uuid' => 'uuid',
                    'organization_name' => 'text',
                ],
                DonorTypeSlug::FRIENDS_OF_ICOBA->value,
                DonorTypeSlug::RELATIVES_OF_ICOBA->value => [
                    'firstname' => 'text',
                    'lastname' => 'text',
                ],
                default => [],
            };
        }

        $properties = [
            'graduation_set_uuid' => 'graduationSetUuid',
            'firstname' => 'firstname',
            'lastname' => 'lastname',
            'corporate_category_uuid' => 'corporateCategoryUuid',
            'organization_name' => 'organizationName',
        ];

        foreach ($checks as $field => $kind) {
            $property = $properties[$field];
            $incoming = $this->{$property};
            $current = $existing->{$property};

            $matches = $kind === 'uuid'
                ? GivingIdentityNormalizer::compareUuid($incoming, $current)
                : GivingIdentityNormalizer::compareText($incoming, $current);

            if (! $matches) {
                $differences[$field] = ['existing' => $current, 'incoming' => $incoming];
            }
        }

        return $differences;
    }

    /**
     * @return array<string, array{existing: mixed, incoming: mixed}>
     */
    public function hardFieldDifferencesFromIdentity(GivingIdentity $identity): array
    {
        return $this->hardFieldDifferences(GivingIdentityProfileBuilder::fromIdentity($identity));
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
            'house' => $this->house,
            'affiliated_graduation_set_uuid' => $this->affiliatedGraduationSetUuid,
            'is_igbobian_owned' => $this->isIgbobianOwned,
            'wives_type' => $this->wivesType,
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
