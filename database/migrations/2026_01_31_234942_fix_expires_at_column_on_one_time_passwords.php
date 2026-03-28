<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //I added this to remove "ON UPDATE" behaviour by switching to DATETIME
        DB::statement("ALTER TABLE one_time_passwords MODIFY expires_at DATETIME NOT NULL");

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE one_time_passwords MODIFY expires_at TIMESTAMP NOT NULL");
    }
};
