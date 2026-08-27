<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('ad_uuid');
            $table->string('image_url');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('ad_uuid')->references('uuid')->on('ads')->cascadeOnDelete();

            $table->index(['ad_uuid', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_images');
    }
};
