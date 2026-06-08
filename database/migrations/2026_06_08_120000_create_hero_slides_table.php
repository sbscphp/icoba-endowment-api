<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hero_slides', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('banner_url');
            $table->string('primary_cta_url');
            $table->string('primary_cta_text', 100);
            $table->string('secondary_cta_url');
            $table->string('secondary_cta_text', 100);
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_deletable')->default(true);
            $table->uuid('created_by_admin_uuid')->nullable();
            $table->uuid('updated_by_admin_uuid')->nullable();
            $table->timestamps();

            $table->foreign('created_by_admin_uuid')
                ->references('uuid')
                ->on('admins')
                ->nullOnDelete();

            $table->foreign('updated_by_admin_uuid')
                ->references('uuid')
                ->on('admins')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hero_slides');
    }
};
