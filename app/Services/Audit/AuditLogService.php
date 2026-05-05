<?php

namespace App\Services\Audit;

use App\Enums\AuditActionEnum;
use App\Enums\AuditModuleEnum;
use App\Enums\UserTypeEnum;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogService
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'access_token',
        'reset_token',
        'challenge_token',
        'otp',
        'code',
        'code_hash',
        'secret',
        'authorization',
    ];

    /**
     * Central audit writer for auth/security events.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        UserTypeEnum $userType,
        AuditActionEnum $action,
        Request $request,
        ?string $userId,
        array $metadata,
        ?string $description,
        ?string $model,
        ?string $modelId,
        AuditModuleEnum $actionModule
    ): void {
        AuditLog::create([
            'user_type' => $userType,
            'user_id' => $userId,
            'action_module' => $actionModule,
            'action' => $action,
            'model' => $model,
            'model_id' => $modelId,
            'description' => $description,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $this->buildMetadata($request, $metadata, $model, $modelId),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildMetadata(Request $request, array $metadata, ?string $model, ?string $modelId): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'route' => optional($request->route())->getName() ?? $request->path(),
            'method' => $request->method(),
            'target' => [
                'model' => $model,
                'model_id' => $modelId,
            ],
            'request' => [
                'ip' => $request->ip(),
                'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
                'payload' => $this->sanitizeMetadata($request->except(self::SENSITIVE_KEYS)),
            ],
            'data' => $this->sanitizeMetadata($metadata),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if ($this->isSensitiveKey($normalizedKey)) {
                $clean[$key] = '[redacted]';

                continue;
            }

            if (str_contains($normalizedKey, 'email') && is_scalar($value)) {
                $clean[$key.'_hash'] = hash('sha256', strtolower(trim((string) $value)));

                continue;
            }

            if (str_contains($normalizedKey, 'phone') && is_scalar($value)) {
                $clean[$key.'_hash'] = hash('sha256', preg_replace('/\D+/', '', (string) $value) ?? '');

                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitizeMetadata($value) : $value;
        }

        return $clean;
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($key, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
