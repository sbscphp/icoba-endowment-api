<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('purpose', 120)->nullable()->after('is_anonymous')
                ->comment('Donation purpose slug (general, student_welfare, infrastructure) or client-supplied custom text');
        });

        Schema::table('pledges', function (Blueprint $table): void {
            $table->string('purpose', 120)->nullable()->after('is_anonymous')
                ->comment('Pledge purpose slug (general, student_welfare, infrastructure) or client-supplied custom text');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });

        Schema::table('pledges', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
