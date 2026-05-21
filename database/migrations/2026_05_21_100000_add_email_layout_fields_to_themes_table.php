<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->string('header_background_color', 20)->default('#010133')->after('logo_url');
            $table->string('logo_path')->nullable()->after('header_background_color');
            $table->string('accent_color', 20)->nullable()->after('secondary_color');
            $table->string('link_color', 20)->nullable()->after('accent_color');
            $table->text('footer_line_one')->nullable()->after('footer_text');
            $table->text('footer_line_two')->nullable()->after('footer_line_one');
        });
    }

    public function down(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->dropColumn([
                'header_background_color',
                'logo_path',
                'accent_color',
                'link_color',
                'footer_line_one',
                'footer_line_two',
            ]);
        });
    }
};
