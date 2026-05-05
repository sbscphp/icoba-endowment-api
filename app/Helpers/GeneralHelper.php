<?php

namespace App\Helpers;

use App\Enums\AuditActionEnum;
use App\Enums\ModuleEnums;
use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Responser\JsonResponser;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class GeneralHelper
{
    public static function handleControllerThrowable(\Throwable $th, string $context)
    {
        if ($th instanceof ApiException) {
            return JsonResponser::send(true, $th->getMessage(), $th->payload, $th->status);
        }

        if ($th instanceof ValidationException) {
            return JsonResponser::send(true, $th->validator->errors()->first() ?: 'Validation error.', $th->errors(), 422);
        }

        if ($th instanceof ModelNotFoundException) {
            return JsonResponser::send(true, 'Record not found.', null, 404);
        }

        if ($th instanceof HttpExceptionInterface) {
            $status = $th->getStatusCode();
            $message = $th->getMessage() !== '' ? $th->getMessage() : 'Request could not be processed.';

            if ($status >= 500) {
                Log::error($context.': '.$message, ['trace' => $th->getTraceAsString()]);
            }

            return JsonResponser::send(true, $message, null, $status, $th);
        }

        Log::error($context.': '.$th->getMessage(), ['trace' => $th->getTraceAsString()]);

        return JsonResponser::send(true, 'An unexpected error occurred. Please try again.', null, 500, $th);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function storeAuditLog(
        UserTypeEnum $userType,
        AuditActionEnum $action,
        Request $request,
        ?string $userId,
        array $metadata,
        ?string $description,
        ?string $model,
        ?string $modelId,
        ModuleEnums $actionModule,
        ?int $httpStatus = null,
    ): void {
        app(AuditLogService::class)->record(
            $userType,
            $action,
            $request,
            $userId,
            $metadata,
            $description,
            $model,
            $modelId,
            $actionModule,
            $httpStatus
        );
    }

    /**
     * @return string|array<string, string>
     */
    public static function getModelUniqueRandomId(array $params): string|array
    {
        $modelClass = $params['modelNamespace'];
        $field = $params['modelField'];
        $prefix = $params['prefix'] ?? '';
        $length = (int) ($params['idLength'] ?? 8);
        $type = $params['idType'] ?? 'alnum';

        $pool = match ($type) {
            'numalpha' => '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
            default => '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
        };

        for ($i = 0; $i < 10; $i++) {
            $random = $prefix;
            for ($j = 0; $j < $length; $j++) {
                $random .= $pool[random_int(0, strlen($pool) - 1)];
            }
            if (! $modelClass::where($field, $random)->exists()) {
                return $random;
            }
        }

        return ['error' => 'Could not generate unique id'];
    }

    public static function getSanitizedRequestData(): ?string
    {
        $data = request()->except([
            'password',
            'password_confirmation',
            'current_password',
            'passcode',
            'current_passcode',
            'cvv',
            'card_number',
            'cardNumber',
        ]);

        $encoded = json_encode($data);

        return $encoded !== false ? $encoded : null;
    }

    public static function maskEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email);
        if (strlen($name) <= 2) {
            return str_repeat('*', strlen($name))."@{$domain}";
        }

        return substr($name, 0, 1).str_repeat('*', strlen($name) - 2).substr($name, -1)."@{$domain}";
    }

    /**
     * Mask email for draw / winner listings: first two characters of local part, remainder asterisks; domain unchanged.
     */
    public static function maskEmailLeadingTwo(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }
        [$name, $domain] = explode('@', $email, 2);
        $len = strlen($name);
        if ($len <= 2) {
            return "{$name}@{$domain}";
        }

        return substr($name, 0, 2).str_repeat('*', $len - 2).'@'.$domain;
    }

    public static function maskPhone(string $phoneNumber, string $countryCode = '+234'): string
    {
        // Remove any non-numeric characters (except the leading '+')
        $cleanedNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // If it starts with the country code, mask digits after it
        if (str_starts_with($cleanedNumber, $countryCode)) {
            $digitsToMask = strlen($cleanedNumber) - strlen($countryCode) - 2;
            if ($digitsToMask > 0) {
                return $countryCode.Str::mask(substr($cleanedNumber, strlen($countryCode)), '*', 0, $digitsToMask);
            } else {
                return $countryCode.substr($cleanedNumber, strlen($countryCode)); // Not enough digits to mask
            }
        } else {
            // If it doesn't start with the country code, mask digits from the beginning
            $digitsToMask = strlen($cleanedNumber) - 2;
            if ($digitsToMask > 0) {
                return Str::mask($cleanedNumber, '*', 0, $digitsToMask);
            } else {
                return $cleanedNumber; // Not enough digits to mask
            }
        }
    }

    /**
     * Mask phone for draw / winner listings: all digits except the last five replaced with asterisks.
     */
    public static function maskPhoneLastFive(?string $phoneNumber): string
    {
        if ($phoneNumber === null || $phoneNumber === '') {
            return 'Unknown';
        }
        $digits = preg_replace('/\D/', '', $phoneNumber) ?? '';
        if ($digits === '') {
            return 'Unknown';
        }
        $len = strlen($digits);
        if ($len <= 5) {
            return $digits;
        }

        return str_repeat('*', $len - 5).substr($digits, -5);
    }
}
