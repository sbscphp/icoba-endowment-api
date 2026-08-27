<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ads_transition_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_uuid', 'uuid');
    }
}
