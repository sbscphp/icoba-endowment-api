@props([
    'rows' => [],
])

@php
    $theme = $theme ?? app(\App\Services\Theme\ThemeResolver::class)->resolveForMail();
    $accentColor = $theme->accentColor();
@endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0; border:1px dashed {{ $theme->border_color }}; border-radius:10px; overflow:hidden;">
    @foreach ($rows as $row)
        <tr>
            <td style="padding:14px 16px; font-size:13px; color:{{ $theme->muted_text_color }}; border-bottom:1px solid {{ $theme->background_color }}; width:45%; vertical-align:top;">
                {{ $row['label'] ?? '' }}
            </td>
            <td style="padding:14px 16px; font-size:15px; font-weight:700; text-align:right; word-break:break-word; color:{{ $row['color'] ?? $accentColor }}; border-bottom:1px solid {{ $theme->background_color }}; vertical-align:top;">
                {{ $row['value'] ?? '' }}
            </td>
        </tr>
    @endforeach
</table>
