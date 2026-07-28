<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('event_uuid');
            $table->string('image_url');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('event_uuid')->references('uuid')->on('events')->cascadeOnDelete();

            $table->index(['event_uuid', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_images');
    }
};
