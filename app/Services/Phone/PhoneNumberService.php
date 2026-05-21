<?php

namespace App\Services\Phone;

use App\Models\Country;
use App\Models\User;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class PhoneNumberService
{
    private readonly PhoneNumberUtil $util;

    public function __construct()
    {
        $this->util = PhoneNumberUtil::getInstance();
    }

    /**
     * Parse and validate a phone number for the selected country.
     *
     * @return array{phone_number: string, country_code: string}|null
     */
    public function normalize(string $rawInput, Country $country): ?array
    {
        $raw = trim($rawInput);
        if ($raw === '') {
            return null;
        }

        $region = strtoupper($country->iso2);

        try {
            $parsed = $this->util->parse($raw, $region);
        } catch (NumberParseException) {
            return null;
        }

        if (! $this->util->isValidNumberForRegion($parsed, $region)) {
            return null;
        }

        $e164 = $this->util->format($parsed, PhoneNumberFormat::E164);

        if (! $this->e164MatchesCountryDialCode($e164, $country->dial_code)) {
            return null;
        }

        return [
            'phone_number' => $e164,
            'country_code' => $country->dial_code,
        ];
    }

    /**
     * Whether a normalized E.164 number is already stored on a user account.
     * Compares against E.164 and common legacy formats (local, digits-only).
     */
    public function isRegistered(string $e164): bool
    {
        if (! str_starts_with($e164, '+')) {
            return false;
        }

        $forms = $this->equivalentStoredValues($e164);

        if ($forms === []) {
            return false;
        }

        return User::query()
            ->whereIn('phone_number', $forms)
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function equivalentStoredValues(string $e164): array
    {
        try {
            $parsed = $this->util->parse($e164, null);
        } catch (NumberParseException) {
            return [$e164];
        }

        $e164Formatted = $this->util->format($parsed, PhoneNumberFormat::E164);
        $digitsOnly = preg_replace('/\D+/', '', $e164Formatted) ?? '';
        $nationalDigits = preg_replace('/\D+/', '', $this->util->format($parsed, PhoneNumberFormat::NATIONAL)) ?? '';

        return array_values(array_unique(array_filter([
            $e164Formatted,
            $digitsOnly,
            $nationalDigits,
        ])));
    }

    /**
     * E.164 digits only (no "+") for SMS gateways.
     */
    public function toSmsDigits(?string $e164Phone): ?string
    {
        $raw = trim((string) $e164Phone);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    /**
     * Ensure formatted E.164 begins with the dial prefix from our countries table.
     * Handles NANP territories stored as +1, +1876, +1242, etc.
     */
    private function e164MatchesCountryDialCode(string $e164, string $dialCode): bool
    {
        $dialDigits = preg_replace('/\D+/', '', $dialCode) ?? '';
        $e164Digits = preg_replace('/\D+/', '', $e164) ?? '';

        if ($dialDigits === '' || $e164Digits === '') {
            return false;
        }

        return str_starts_with($e164Digits, $dialDigits);
    }
}
