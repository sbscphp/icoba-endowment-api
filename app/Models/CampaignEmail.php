<?php

namespace App\Models;

use App\Enums\BulkEmailStatus;
use App\Enums\EmailDesignTemplate;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CampaignEmail extends Model
{
    use HasUuid, SoftDeletes;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'design_template' => EmailDesignTemplate::class,
            'recipient_audience' => 'array',
            'status' => BulkEmailStatus::class,
            'is_active' => 'boolean',
            'total_recipients' => 'integer',
            'successful_count' => 'integer',
            'failed_count' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_uuid', 'uuid');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'sent_by_admin_uuid', 'uuid');
    }
}
