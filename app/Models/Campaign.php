<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

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
            'is_default' => 'boolean',
            'status' => CampaignStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Campaign $campaign): void {
            if ($campaign->wasChanged(['is_default', 'uuid'])) {
                Cache::forget('campaigns.default_uuid');
            }
        });

        static::deleted(function (): void {
            Cache::forget('campaigns.default_uuid');
        });
    }

    public static function defaultCampaign(): Campaign
    {
        $uuid = Cache::rememberForever('campaigns.default_uuid', function (): ?string {
            return self::query()->where('is_default', true)->value('uuid');
        });

        if ($uuid === null || $uuid === '') {
            throw new \RuntimeException('Default campaign is not configured. Run DefaultCampaignSeeder.');
        }

        $campaign = self::query()->where('uuid', $uuid)->first();

        if ($campaign === null) {
            Cache::forget('campaigns.default_uuid');
            throw new \RuntimeException('Default campaign is not configured. Run DefaultCampaignSeeder.');
        }

        return $campaign;
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
}
