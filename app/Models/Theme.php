<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Theme extends Model
{
    use HasUuid;
    protected $guarded = ['id', 'uuid'];

    protected $casts = [
        'is_default' => 'boolean',
    ];
}
