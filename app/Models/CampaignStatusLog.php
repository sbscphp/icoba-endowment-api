<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignStatusLog extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'to_status' => CampaignStatus::class,
            'snapshot_actual_start_date' => 'datetime',
            'snapshot_actual_end_date' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_uuid', 'uuid');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'actor_admin_uuid', 'uuid');
    }
}
