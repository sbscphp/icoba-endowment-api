<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_update_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('report_id', 20)->unique();
            $table->uuid('campaign_uuid');
            $table->string('name');
            $table->text('short_description');
            $table->longText('details');
            $table->string('banner_url');
            $table->string('youtube_link')->nullable();
            $table->boolean('is_active')->default(false);
            $table->uuid('created_by_admin_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('campaign_uuid')
                ->references('uuid')
                ->on('campaigns')
                ->cascadeOnDelete();

            $table->foreign('created_by_admin_uuid')
                ->references('uuid')
                ->on('admins')
                ->nullOnDelete();

            $table->index(['campaign_uuid', 'is_active']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_update_reports');
    }
};
