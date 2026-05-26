<?php

namespace App\Models;

use App\Enums\IssuedCertificateStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonorRecognition extends Model
{
    use HasUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'cumulative_amount_ngn' => 'decimal:2',
            'initial_amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'status' => IssuedCertificateStatus::class,
            'snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<TierConfiguration, $this>
     */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(TierConfiguration::class, 'tier_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<CertificateTemplate, $this>
     */
    public function certificateTemplate(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class, 'certificate_template_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function triggerTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'trigger_transaction_uuid', 'uuid');
    }
}
