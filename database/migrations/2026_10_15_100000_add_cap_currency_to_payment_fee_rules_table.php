<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a rule cap its fee in a currency other than the one it prices (e.g. a USD rule capped
 * at 2,000 NGN). The cap is converted into the rule currency at quote time with the latest
 * stored exchange rate. Null means the cap is already in the rule currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_fee_rules', function (Blueprint $table) {
            $table->string('cap_currency', 8)->nullable()->after('cap_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_fee_rules', function (Blueprint $table) {
            $table->dropColumn('cap_currency');
        });
    }
};
