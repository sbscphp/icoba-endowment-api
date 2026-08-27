<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('ads_transition_seconds')->default(5);
            $table->uuid('updated_by_admin_uuid')->nullable();
            $table->timestamps();

            $table->foreign('updated_by_admin_uuid')->references('uuid')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_settings');
    }
};
