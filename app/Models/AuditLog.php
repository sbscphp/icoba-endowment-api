<?php

namespace App\Models;

use App\Enums\AuditActionEnum;
use App\Enums\ModuleEnums;
use App\Enums\UserTypeEnum;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'user_type' => UserTypeEnum::class,
            'action_module' => ModuleEnums::class,
            'action' => AuditActionEnum::class,
            'metadata' => 'array',
            'http_status' => 'integer',
        ];
    }

    public function customerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'user_id', 'uuid');
    }
}
