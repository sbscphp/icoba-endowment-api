<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('set_number')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
