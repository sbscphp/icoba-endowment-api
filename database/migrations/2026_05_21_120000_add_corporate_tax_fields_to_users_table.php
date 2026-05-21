<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('rc_number', 64)->nullable()->after('organization_name')->comment('Corporate registration number');
            $table->string('tin', 64)->nullable()->after('rc_number')->comment('Tax identification number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['rc_number', 'tin']);
        });
    }
};
