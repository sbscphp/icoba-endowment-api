<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_notifications_enabled')->default(true)->after('2fa_expires_at');
            $table->boolean('push_notifications_enabled')->default(true)->after('email_notifications_enabled');
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->boolean('email_notifications_enabled')->default(true)->after('2fa');
            $table->boolean('push_notifications_enabled')->default(true)->after('email_notifications_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['email_notifications_enabled', 'push_notifications_enabled']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_notifications_enabled', 'push_notifications_enabled']);
        });
    }
};

