<?php

namespace Database\Seeders;

use App\Models\Theme;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ThemeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Theme::query()->update(['is_default' => false]);

        Theme::updateOrCreate(
            ['name' => 'Default Theme'],
            [
                'is_default' => true,
                'brand_name' => config('app.name'),
                'logo_url' => config('app.url').'/assets/logo/'.Str::slug(config('app.name')).'.png',

                'primary_color' => '#F9EC3E',
                'secondary_color' => '#1D2939',
                'background_color' => '#FBEAEA',
                'surface_color' => '#FFFFFF',
                'text_color' => '#111827',
                'muted_text_color' => '#6B7280',
                'border_color' => '#1D2939',
                'button_primary_bg' => '#4F46E5',
                'button_primary_text' => '#FFFFFF',
                'button_secondary_bg' => '#111827',
                'button_secondary_text' => '#FFFFFF',

                'font_family' => 'Arial, Helvetica, sans-serif',
                'heading_font_family' => null,

                'support_email' => 'support@'.Str::slug(config('app.name')).'.com',
                'footer_text' => 'If you did not request this, you can safely ignore this email.',
            ]
        );
    }
}
