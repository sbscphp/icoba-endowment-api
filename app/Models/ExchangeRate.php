<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'rate_to_naira' => 'decimal:6',
            'effective_date' => 'date',
        ];
    }
}
