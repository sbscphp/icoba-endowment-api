<?php

namespace App\Services\Admin\ContentManagement\Concerns;

use App\Enums\AuditActionEnum;
use App\Enums\ModuleEnums;
use App\Enums\UserTypeEnum;
use App\Helpers\GeneralHelper;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes content-management audit entries against the current request.
 */
trait AuditsContentChanges
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordContentAudit(
        AuditActionEnum $action,
        Model $model,
        string $description,
        array $metadata,
        ?string $adminUuid,
    ): void {
        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            $action,
            request(),
            $adminUuid,
            $metadata,
            $description,
            $model::class,
            (string) ($model->getAttribute('uuid') ?? $model->getKey()),
            ModuleEnums::content_management,
            200,
        );
    }

    /**
     * Changed field names and their previous values. Call after fill() and before save().
     *
     * @return array{changed_fields: list<string>, previous: array<string, mixed>}
     */
    private function pendingChanges(Model $model): array
    {
        $fields = array_values(array_diff(array_keys($model->getDirty()), ['updated_by_admin_uuid', 'updated_at']));

        $previous = [];
        foreach ($fields as $field) {
            $previous[$field] = $model->getRawOriginal($field);
        }

        return ['changed_fields' => $fields, 'previous' => $previous];
    }
}
