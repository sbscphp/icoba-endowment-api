<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tier_configurations', function (Blueprint $table) {
            $table->string('tier_badge_url', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tier_configurations', function (Blueprint $table) {
            $table->dropColumn('tier_badge_url');
        });
    }
};
