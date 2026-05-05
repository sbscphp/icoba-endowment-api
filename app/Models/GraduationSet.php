<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GraduationSet extends Model
{
    use HasUuid;

    protected $table = 'sets';

    protected $guarded = ['id', 'uuid'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'graduation_set_uuid', 'uuid');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_uuid', 'uuid');
    }
}
