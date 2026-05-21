@props([
    'url' => '#',
    'label' => 'Click',
    'variant' => 'primary',
])

@php
    $theme = $theme ?? app(\App\Services\Theme\ThemeResolver::class)->resolveForMail();
    $bg = $variant === 'secondary' ? $theme->button_secondary_bg : $theme->button_primary_bg;
    $fg = $variant === 'secondary' ? $theme->button_secondary_text : $theme->button_primary_text;
@endphp

<table role="presentation" cellspacing="0" cellpadding="0" style="margin: 16px 0;">
    <tr>
        <td bgcolor="{{ $bg }}" style="border-radius: 10px;">
            <a href="{{ $url }}"
                style="display:inline-block; padding:12px 18px; text-decoration:none; color:{{ $fg }}; font-weight:700; font-size:14px;">
                {{ $label }}
            </a>
        </td>
    </tr>
</table>
