<?php

namespace App\Enums;

enum TierBenefit: string
{
    case PREMIUM_CERTIFICATE = 'premium_certificate';
    case WEBSITE_RECOGNITION = 'website_recognition';
    case VIP_DINNER_INVITE = 'vip_dinner_invite';
    case FEATURED_NEWSLETTER_MENTION = 'featured_newsletter_mention';
    case EVENT_PRIORITY_SEATING = 'event_priority_seating';
    case DONOR_WALL_LISTING = 'donor_wall_listing';

    public function label(): string
    {
        return match ($this) {
            self::PREMIUM_CERTIFICATE => 'Premium certificate',
            self::WEBSITE_RECOGNITION => 'Featured recognition on website',
            self::VIP_DINNER_INVITE => 'VIP annual dinner invite',
            self::FEATURED_NEWSLETTER_MENTION => 'Featured mention in newsletter',
            self::EVENT_PRIORITY_SEATING => 'Priority seating at events',
            self::DONOR_WALL_LISTING => 'Donor wall listing',
        };
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $benefit): array => [
                'value' => $benefit->value,
                'label' => $benefit->label(),
            ],
            self::cases()
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
