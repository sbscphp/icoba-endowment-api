<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('can_login')->default(true)->index();
            $table->timestamp('last_login_at')->nullable()->index();
            $table->unsignedTinyInteger('login_attempts')->default(0);
            $table->boolean('is_locked')->default(false)->index();
            $table->timestamp('locked_at')->nullable()->index();
            $table->timestamp('last_active_at')->nullable()->index();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
