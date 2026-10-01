<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('about_sections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('section_key')->unique();
            $table->longText('content')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->uuid('updated_by_admin_uuid')->nullable();
            $table->timestamps();

            $table->foreign('updated_by_admin_uuid')
                ->references('uuid')
                ->on('admins')
                ->nullOnDelete();
        });

        // The four sections are fixed; timestamps stay null until an admin first edits or toggles one.
        foreach (['overview', 'vision_mission', 'implementation_plan', 'pdf_viewer'] as $sectionKey) {
            DB::table('about_sections')->insert([
                'uuid' => (string) Str::uuid(),
                'section_key' => $sectionKey,
                'content' => null,
                'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('about_sections');
    }
};
