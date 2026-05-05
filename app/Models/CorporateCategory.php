<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorporateCategory extends Model
{
    use HasUuid;

    protected $guarded = ['id', 'uuid'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'corporate_category_uuid', 'uuid');
    }
}
