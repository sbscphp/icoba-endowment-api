<?php

namespace App\Models;

use App\Enums\AuditActionEnum;
use App\Enums\AuditModuleEnum;
use App\Enums\UserTypeEnum;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'user_type' => UserTypeEnum::class,
            'action_module' => AuditModuleEnum::class,
            'action' => AuditActionEnum::class,
            'metadata' => 'array',
        ];
    }
}
