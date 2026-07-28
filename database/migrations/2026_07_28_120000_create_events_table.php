<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('event_id', 20)->unique();
            $table->string('title');
            $table->string('short_description', 500);
            $table->longText('long_description');
            $table->date('event_date');
            $table->string('banner_url');
            $table->string('status', 20)->default('draft')->index();
            $table->uuid('created_by_admin_uuid')->nullable();
            $table->uuid('updated_by_admin_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_admin_uuid')->references('uuid')->on('admins')->nullOnDelete();
            $table->foreign('updated_by_admin_uuid')->references('uuid')->on('admins')->nullOnDelete();

            $table->index('event_date');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
