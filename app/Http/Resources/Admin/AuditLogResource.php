<?php

namespace App\Http\Resources\Admin;

use App\Enums\UserTypeEnum;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'user_type' => $this->user_type->value,
            'action_module' => $this->action_module->value,
            'action' => $this->action->value,
            'description' => $this->description,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'http_outcome' => $this->httpOutcome(),
            'created_at' => $this->created_at,
            $this->mergeWhen(
                $this->user_type === UserTypeEnum::CUSTOMER
                    && $this->relationLoaded('customerUser')
                    && $this->customerUser !== null,
                fn (): array => ['admin' => $this->customerSummary()]
            ),
            $this->mergeWhen(
                $this->user_type === UserTypeEnum::ADMIN
                    && $this->relationLoaded('adminUser')
                    && $this->adminUser !== null,
                fn (): array => ['admin' => [
                    'uuid' => $this->adminUser->uuid,
                    'name' => $this->adminUser->name,
                    'email' => $this->adminUser->email,
                ]]
            ),
        ];
    }

    private function httpOutcome(): ?string
    {
        $code = $this->http_status;

        if ($code === null) {
            return null;
        }

        return $code >= 200 && $code < 300 ? 'success' : 'error';
    }

    /**
     * @return array{uuid: string, name: string|null, email: string|null}
     */
    private function customerSummary(): array
    {
        $user = $this->customerUser;
        $name = trim(implode(' ', array_filter([$user->firstname ?? '', $user->lastname ?? ''])));

        return [
            'uuid' => $user->uuid,
            'name' => $name !== '' ? $name : null,
            'email' => $user->email,
        ];
    }
}
