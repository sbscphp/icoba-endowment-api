<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('firstname');
            $table->string('lastname');
            $table->string('middlename')->nullable();
            $table->string('email')->unique();
            $table->string('phone_number')->nullable();
            $table->string('country_code', 10)->nullable()->comment('prefix e.g. +234, 234');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('2fa')->default(false)->comment('True, False');
            $table->dateTime('2fa_expires_at')->nullable();
            $table->boolean('email_notifications_enabled')->default(true);
            $table->boolean('push_notifications_enabled')->default(true);
            $table->boolean('biometrics_enabled')->default(false);
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

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
