<?php

namespace App\Models;

use App\Enums\PublicDocumentType;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class PublicDocumentDownloadToken extends Model
{
    use HasUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'document_type' => PublicDocumentType::class,
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
