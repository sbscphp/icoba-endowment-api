<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Notifications\Auth\ResetPasswordMail;
use App\Traits\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuid, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = ['uuid', 'id'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'stripe_customer_id',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        '2fa_expires_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            '2fa' => 'boolean',
            'password' => 'hashed',
            'email_notifications_enabled' => 'boolean',
            'push_notifications_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'login_attempts' => 'integer',
            'is_locked' => 'boolean',
            'locked_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    public function donorType(): BelongsTo
    {
        return $this->belongsTo(DonorType::class, 'donor_type_uuid', 'uuid');
    }

    public function corporateCategory(): BelongsTo
    {
        return $this->belongsTo(CorporateCategory::class, 'corporate_category_uuid', 'uuid');
    }

    public function graduationSet(): BelongsTo
    {
        return $this->belongsTo(GraduationSet::class, 'graduation_set_uuid', 'uuid');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'user_uuid', 'uuid');
    }

    public function pledges(): HasMany
    {
        return $this->hasMany(Pledge::class, 'user_uuid', 'uuid');
    }

    public function displayName(): string
    {
        if (filled($this->organization_name)) {
            return trim((string) $this->organization_name);
        }

        $name = trim(implode(' ', array_filter([
            (string) ($this->firstname ?? ''),
            (string) ($this->lastname ?? ''),
        ])));

        return $name !== '' ? $name : (string) $this->email;
    }

    public function sendPasswordResetNotification($token): void
    {
        $resetUrl = config('app.frontend_url')
            .'/reset-password?token='.$token
            .'&email='.urlencode($this->email);

        $this->notify(new ResetPasswordMail($token, $resetUrl));
    }
}
