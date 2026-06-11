<?php

namespace App\Services\ThirdParty\SMS;

use App\Models\Country;
use App\Support\SmsMode;
use App\Services\Phone\PhoneNumberService;
use Illuminate\Support\Facades\Log;

class SmsService
{
    public function __construct(
        private readonly InfobipService $infobipService,
        private readonly TermiiService $termiiService,
        private readonly TwilioService $twilioService,
        private readonly PhoneNumberService $phoneNumberService,
    ) {}

    /**
     * @param  ?string  $countryCode  Dialing prefix (e.g. "+234", "234"); optional for legacy rows where {@see $nationalNumber} is already international digits.
     * @param  ?string  $nationalNumber  Subscriber number without country prefix (preferred); digits-only international also accepted when paired with {@see $countryCode}.
     */
    public function sendOtp(?string $countryCode, ?string $nationalNumber, string $otp, int $expiresInMinutes, string $purposeLabel): void
    {
        $phoneNumber = $this->resolveSmsDigits($countryCode, $nationalNumber);
        if ($phoneNumber === null) {
            return;
        }

        $message = sprintf(
            'Your %s %s code is %s. It expires in %d minutes.',
            config('app.name', 'Laravel'),
            $purposeLabel,
            $otp,
            $expiresInMinutes
        );

        $driver = $this->resolveDriver($phoneNumber);

        try {
            match ($driver) {
                'infobip' => $this->infobipService->send($phoneNumber, $message),
                'termii' => $this->termiiService->send($phoneNumber, $message),
                'twilio' => $this->twilioService->send($phoneNumber, $message),
                'log' => Log::info('OTP SMS prepared', ['to' => $phoneNumber, 'purpose' => $purposeLabel]),
                default => Log::warning('OTP SMS skipped: unknown provider', [
                    'driver' => $driver,
                    'to' => $phoneNumber,
                ]),
            };
        } catch (\Throwable $th) {
            // OTP email delivery still proceeds; avoid leaking provider failure to public auth flows.
            Log::error('OTP SMS delivery failed', [
                'driver' => $driver,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function resolveDriver(string $phoneDigits): string
    {
        return match (SmsMode::current()) {
            SmsMode::LOG => 'log',
            SmsMode::LIVE => $this->resolveLiveProvider($phoneDigits),
            default => 'log',
        };
    }

    private function resolveLiveProvider(string $phoneDigits): string
    {
        if ($this->isNigerianNumber($phoneDigits)) {
            return 'termii';
        }

        $foreignProvider = strtolower(trim((string) config('services.sms.foreign_provider', 'twilio')));

        if (in_array($foreignProvider, ['infobip', 'twilio'], true)) {
            return $foreignProvider;
        }

        Log::warning('SMS foreign provider invalid; falling back to twilio', [
            'configured' => $foreignProvider,
        ]);

        return 'twilio';
    }

    private function isNigerianNumber(string $phoneDigits): bool
    {
        return str_starts_with($phoneDigits, '234');
    }

    private function resolveSmsDigits(?string $countryCode, ?string $nationalNumber): ?string
    {
        $raw = trim((string) $nationalNumber);
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            return $this->phoneNumberService->toSmsDigits($raw);
        }

        $country = Country::findActiveByDialCode($countryCode);
        if ($country !== null) {
            $normalized = $this->phoneNumberService->normalize($raw, $country);
            if ($normalized !== null) {
                return $this->phoneNumberService->toSmsDigits($normalized['phone_number']);
            }
        }

        return $this->legacyNormalizeForSms($countryCode, $raw);
    }

    private function legacyNormalizeForSms(?string $countryCode, ?string $nationalNumber): ?string
    {
        $nationalRaw = trim((string) $nationalNumber);
        if ($nationalRaw === '') {
            return null;
        }

        $ccDigits = $this->callingCodeDigits($countryCode);

        if ($ccDigits !== null) {
            $nationalDigits = preg_replace('/\D+/', '', $nationalRaw) ?? '';
            if ($nationalDigits === '') {
                Log::debug('SMS skipped: national phone number has no digits', [
                    'country_code' => $countryCode,
                ]);

                return null;
            }

            if (str_starts_with($nationalDigits, '0')) {
                $nationalDigits = substr($nationalDigits, 1);
            }

            if ($nationalDigits === '') {
                return null;
            }

            if (str_starts_with($nationalDigits, $ccDigits) && strlen($nationalDigits) > strlen($ccDigits)) {
                $nationalDigits = substr($nationalDigits, strlen($ccDigits));
            }

            if (strlen($ccDigits) < 1 || strlen($ccDigits) > 4) {
                Log::debug('SMS skipped: invalid country calling code length', [
                    'country_code' => $countryCode,
                    'cc_digits_len' => strlen($ccDigits),
                ]);

                return null;
            }

            $e164Digits = $ccDigits.$nationalDigits;
            if (strlen($e164Digits) < 8 || strlen($e164Digits) > 15) {
                Log::debug('SMS skipped: combined number length outside E.164 range', [
                    'country_code' => $countryCode,
                    'length' => strlen($e164Digits),
                ]);

                return null;
            }

            return $e164Digits;
        }

        $digits = preg_replace('/\D+/', '', $nationalRaw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            Log::debug('SMS skipped: legacy phone length outside E.164 range', ['length' => strlen($digits)]);

            return null;
        }

        return $digits;
    }

    private function callingCodeDigits(?string $countryCode): ?string
    {
        $trimmed = trim((string) $countryCode);
        if ($trimmed === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        return $digits !== '' ? $digits : null;
    }
}
