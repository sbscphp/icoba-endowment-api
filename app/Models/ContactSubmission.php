<?php

namespace App\Models;

use App\Enums\ContactSubmissionStatus;
use App\Enums\ContactSubmissionUserType;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactSubmission extends Model
{
    use HasUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'user_type' => ContactSubmissionUserType::class,
            'status' => ContactSubmissionStatus::class,
            'resolved_at' => 'datetime',
            'email_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function handledByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'handled_by_admin_uuid', 'uuid');
    }
}
