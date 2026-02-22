@php
    /** @var \App\Models\Theme $theme */
@endphp

<!doctype html>
<html>

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
</head>

<body
    style="margin:0; padding:0; background: {{ $theme->background_color }}; font-family: {{ $theme->font_family }}; color: {{ $theme->text_color }};">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
        style="background: {{ $theme->background_color }}; padding: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0"
                    style="background: {{ $theme->surface_color }}; border-radius: 12px; overflow:hidden; border:1px solid {{ $theme->border_color }};">

                    <tr>
                        <td style="padding: 18px 24px; background: {{ $theme->surface_color }};">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>

                                    <td align="left" valign="middle" style="width: 1%; white-space: nowrap;">
                                        @if ($theme->logo_url)
                                            <img src="{{ $theme->logo_url }}"
                                                alt="{{ $theme->brand_name ?? config('app.name') }}" width="44"
                                                style="display:block; max-width:44px; height:auto; border:0; outline:none; text-decoration:none;" />
                                        @endif
                                    </td>

                                    <td style="width: 12px;"></td>

                                    <td align="left" valign="middle"
                                        style="font-weight: 800; font-size: 18px; color: {{ $theme->secondary_color }};">
                                        {{ $theme->brand_name ?? config('app.name') }}
                                    </td>

                                    <td align="right" valign="middle"
                                        style="font-size:12px; color: {{ $theme->muted_text_color }};">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>


                    <tr>
                        <td style="padding: 24px;">
                            @yield('content')
                        </td>
                    </tr>

                    <tr>
                        <td
                            style="padding: 16px 24px; border-top:1px solid {{ $theme->border_color }}; color: {{ $theme->muted_text_color }}; font-size:12px;">
                            {{ $theme->footer_text ?? 'If you did not request this, you can safely ignore this email.' }}
                            @if ($theme->support_email)
                                <br />Support: {{ $theme->support_email }}
                            @endif
                        </td>
                    </tr>
                </table>

                <div style="color: {{ $theme->muted_text_color }}; font-size: 12px; padding-top: 12px;">
                    © {{ now()->year }} {{ $theme->brand_name ?? config('app.name') }}
                </div>
            </td>
        </tr>
    </table>
</body>

</html>
