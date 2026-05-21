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

    public function accentColor(): string
    {
        return (string) ($this->accent_color ?: $this->secondary_color ?: '#122168');
    }

    public function linkColor(): string
    {
        return (string) ($this->link_color ?: $this->accentColor());
    }

    public function footerLineOne(): string
    {
        return (string) ($this->footer_line_one ?: $this->footer_text ?: 'Automated notification - please do not reply.');
    }

    public function footerLineTwo(): string
    {
        return (string) ($this->footer_line_two ?: "If you didn't expect this email, please ignore this message.");
    }

    public function footerBrandName(): string
    {
        return (string) ($this->brand_name ?: config('app.name'));
    }
}
