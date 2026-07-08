<?php

namespace App\Services;

use App\Enums\OtpChannelEnum;
use App\Http\Resources\CountryResource;
use App\Models\Country;

final class DropdownService
{
    /**
     * Options exposed on customer registration metadata (sign-up dropdowns).
     *
     * @return array{
     *     countries: array<int, array<string, mixed>>,
     *     otp_channels: list<array{value: string, label: string}>,
     *     default_country_uuid: string,
     *     default_dial_code: string,
     * }
     */
    public function customerRegistrationMetadata(): array
    {
        $defaultCountry = Country::defaultCountry();

        return [
            'countries' => CountryResource::collection(
                Country::query()->active()->orderBy('name')->get()
            )->resolve(),
            'otp_channels' => [
                ['value' => OtpChannelEnum::EMAIL->value, 'label' => 'Email'],
                ['value' => OtpChannelEnum::SMS->value, 'label' => 'SMS'],
            ],
            'default_country_uuid' => $defaultCountry->uuid,
            'default_dial_code' => $defaultCountry->dial_code,
        ];
    }
}
