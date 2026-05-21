<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('organization_name', 150)->nullable()->after('donor_name');
            $table->string('rc_number', 64)->nullable()->after('organization_name');
            $table->string('tin', 64)->nullable()->after('rc_number');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['organization_name', 'rc_number', 'tin']);
        });
    }
};
