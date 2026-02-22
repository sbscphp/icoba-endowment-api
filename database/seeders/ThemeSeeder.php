<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Theme;

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
                'brand_name' => 'Quiva',
                'logo_url' => config('app.url') . '/assets/logo/v-logo.jpg',

                'primary_color' => '#CC0808',
                'secondary_color' => '#1F2937',
                'background_color' => '#FBEAEA',
                'surface_color' => '#FFFFFF',
                'text_color' => '#111827',
                'muted_text_color' => '#6B7280',
                'border_color' => '#F1B5B5',
                'button_primary_bg' => '#4F46E5',
                'button_primary_text' => '#FFFFFF',
                'button_secondary_bg' => '#111827',
                'button_secondary_text' => '#FFFFFF',

                'font_family' => 'Arial, Helvetica, sans-serif',
                'heading_font_family' => null,

                'support_email' => 'support@quiva.com',
                'footer_text' => 'If you did not request this, you can safely ignore this email.',
            ]
        );
    }
}
