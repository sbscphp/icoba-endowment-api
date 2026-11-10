<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('user_uuid')->index()->comment('UUID of the closed customer account (row is kept, anonymised)');
            $table->string('email')->index()->comment('Email the account had at deletion time');
            $table->string('display_name');
            $table->string('reason', 500)->nullable()->comment('Reason given by the donor');
            $table->json('profile')->comment('Snapshot of the profile as it was before anonymisation');
            $table->json('summary')->nullable()->comment('Counts: pledges cancelled, tokens revoked, donations kept');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('account_closed_at')->nullable()->index()->after('last_active_at')
                ->comment('Set when the donor deleted their account; personal data is anonymised');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['account_closed_at']);
            $table->dropColumn('account_closed_at');
        });

        Schema::dropIfExists('account_deletions');
    }
};
