<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->string('remote_uuid')->nullable()->unique()->after('uuid')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->dropColumn('remote_uuid');
        });
    }
};
