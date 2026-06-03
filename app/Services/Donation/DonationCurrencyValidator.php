<?php

namespace App\Services\Donation;

use App\Enums\Currency;
use App\Models\Campaign;
use Illuminate\Validation\ValidationException;

final class DonationCurrencyValidator
{
    public function assertAllowed(string $currency, string $campaignUuid): void
    {
        $normalized = strtoupper(trim($currency));

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'currency' => ['Currency is required.'],
            ]);
        }

        if (! in_array($normalized, Currency::values(), true)) {
            throw ValidationException::withMessages([
                'currency' => ['The selected currency is not supported.'],
            ]);
        }

        if ($campaignUuid === '') {
            throw ValidationException::withMessages([
                'campaign_uuid' => ['Campaign is required.'],
            ]);
        }

        $campaign = Campaign::query()->where('uuid', $campaignUuid)->firstOrFail();

        $allowed = collect(is_array($campaign->available_donation_currencies) ? $campaign->available_donation_currencies : [])
            ->map(fn (mixed $value): string => strtoupper(trim((string) $value)))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        if ($allowed === []) {
            return;
        }

        if (! in_array($normalized, $allowed, true)) {
            throw ValidationException::withMessages([
                'currency' => ['This currency is not accepted for the selected campaign.'],
            ]);
        }
    }
}
