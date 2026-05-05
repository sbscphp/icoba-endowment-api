<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Api\ApiEncryptionMode;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $name
 * @property string $email
 * @property string $client_key
 * @property string $encryption_key
 * @property string $iv
 * @property ApiEncryptionMode $encryption_mode
 * @property bool $is_active
 */
class ApiUser extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'encryption_mode' => ApiEncryptionMode::class,
            'is_active' => 'boolean',
        ];
    }
}
