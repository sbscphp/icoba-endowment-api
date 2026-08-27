<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('ad_code', 20)->unique();
            $table->string('title');
            $table->string('target_url', 2048)->nullable();
            $table->unsignedInteger('image_interval_seconds')->default(3);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->uuid('created_by_admin_uuid')->nullable();
            $table->uuid('updated_by_admin_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_admin_uuid')->references('uuid')->on('admins')->nullOnDelete();
            $table->foreign('updated_by_admin_uuid')->references('uuid')->on('admins')->nullOnDelete();

            $table->index(['starts_at', 'ends_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads');
    }
};
