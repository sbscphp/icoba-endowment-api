<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('full_name', 120);
            $table->string('email', 191)->index();
            $table->string('user_type', 50)->index();
            $table->text('description');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->uuid('handled_by_admin_uuid')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamps();

            $table->foreign('handled_by_admin_uuid')
                ->references('uuid')
                ->on('admins')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
    }
};
