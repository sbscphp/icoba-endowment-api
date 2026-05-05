<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class OneTimePassword extends Model
{
    use HasUuid;
    
    protected $guarded = ['id', 'uuid'];

}
