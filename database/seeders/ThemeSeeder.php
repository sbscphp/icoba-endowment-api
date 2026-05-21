<?php

namespace Database\Seeders;

use App\Models\Theme;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ThemeSeeder extends Seeder
{
    public function run(): void
    {
        Theme::query()->update(['is_default' => false]);

        $appSlug = Str::slug((string) config('app.name'));

        Theme::updateOrCreate(
            ['name' => 'Default Theme'],
            [
                'is_default' => true,
                'brand_name' => config('app.name'),
                'logo_url' => rtrim((string) config('app.url'), '/').'/assets/logo/'.$appSlug.'.png',
                'logo_path' => 'assets/logo/'.$appSlug.'.png',
                'header_background_color' => '#010133',

                'primary_color' => '#122168',
                'secondary_color' => '#122168',
                'accent_color' => '#122168',
                'link_color' => '#122168',
                'background_color' => '#F5F7FB',
                'surface_color' => '#FFFFFF',
                'text_color' => '#1F2937',
                'muted_text_color' => '#6B7280',
                'border_color' => '#D1D5DB',
                'button_primary_bg' => '#122168',
                'button_primary_text' => '#FFFFFF',
                'button_secondary_bg' => '#374151',
                'button_secondary_text' => '#FFFFFF',

                'font_family' => "'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
                'heading_font_family' => null,

                'support_email' => 'support@icoba.com',
                'footer_text' => 'Automated notification - please do not reply.',
                'footer_line_one' => 'Automated notification - please do not reply.',
                'footer_line_two' => "If you didn't expect this email, please ignore this message.",
            ]
        );
    }
}
