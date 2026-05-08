<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_emails', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('campaign_uuid');
            $table->string('title', 60);
            $table->longText('content');
            $table->string('design_template', 20);
            $table->string('recipient_audience_single', 32)->nullable();
            $table->json('recipient_audience')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('total_recipients')->nullable();
            $table->unsignedInteger('successful_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->uuid('created_by_admin_uuid')->nullable();
            $table->uuid('sent_by_admin_uuid')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('campaign_uuid')->references('uuid')->on('campaigns')->cascadeOnDelete();
            $table->foreign('created_by_admin_uuid')->references('uuid')->on('admins')->nullOnDelete();
            $table->foreign('sent_by_admin_uuid')->references('uuid')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_emails');
    }
};
