<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasUuid, SoftDeletes;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'available_donation_currencies' => 'array',
            'gallery_images' => 'array',
            'target_amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'actual_start_date' => 'datetime',
            'actual_end_date' => 'datetime',
            'allow_anonymous_donation' => 'boolean',
            'allow_public_donation' => 'boolean',
            'applies_to_all_graduation_sets' => 'boolean',
            'status' => CampaignStatus::class,
        ];
    }

    public function graduationSets(): BelongsToMany
    {
        return $this->belongsToMany(
            GraduationSet::class,
            'campaign_graduation_set',
            'campaign_uuid',
            'graduation_set_uuid',
            'uuid',
            'uuid'
        );
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'campaign_uuid', 'uuid');
    }

    public function pledges(): HasMany
    {
        return $this->hasMany(Pledge::class, 'campaign_uuid', 'uuid');
    }

    public function bulkEmails(): HasMany
    {
        return $this->hasMany(CampaignEmail::class, 'campaign_uuid', 'uuid');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(CampaignStatusLog::class, 'campaign_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_uuid', 'uuid');
    }

    /**
     * @return HasMany<CampaignUpdateReport, $this>
     */
    public function updateReports(): HasMany
    {
        return $this->hasMany(CampaignUpdateReport::class, 'campaign_uuid', 'uuid');
    }
}
