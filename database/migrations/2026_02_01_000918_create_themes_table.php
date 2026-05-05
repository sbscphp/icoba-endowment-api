<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->boolean('is_default')->default(true);
            $table->string('brand_name')->nullable();
            $table->string('logo_url')->nullable();

            // Colors
            $table->string('primary_color', 20)->default('#4F46E5');
            $table->string('secondary_color', 20)->default('#111827');
            $table->string('background_color', 20)->default('#F5F7FB');
            $table->string('surface_color', 20)->default('#FFFFFF');
            $table->string('text_color', 20)->default('#111827');
            $table->string('muted_text_color', 20)->default('#6B7280');
            $table->string('border_color', 20)->default('#E5E7EB');

            // Buttons
            $table->string('button_primary_bg', 20)->default('#4F46E5');
            $table->string('button_primary_text', 20)->default('#FFFFFF');
            $table->string('button_secondary_bg', 20)->default('#111827');
            $table->string('button_secondary_text', 20)->default('#FFFFFF');

            // Fonts
            $table->string('font_family')->default("Arial, Helvetica, sans-serif");
            $table->string('heading_font_family')->nullable(); 

            $table->string('support_email')->nullable();
            $table->string('footer_text')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
