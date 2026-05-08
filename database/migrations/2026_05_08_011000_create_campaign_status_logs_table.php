<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_status_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('campaign_uuid');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->uuid('actor_admin_uuid')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('snapshot_actual_start_date')->nullable();
            $table->timestamp('snapshot_actual_end_date')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('campaign_uuid')->references('uuid')->on('campaigns')->cascadeOnDelete();
            $table->foreign('actor_admin_uuid')->references('uuid')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_status_logs');
    }
};
