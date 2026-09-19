<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pledges', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('status')->index();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('status')->index();
        });

        // Existing rows default to live. The only historical signal we can trust is Stripe's
        // checkout session prefix; everything else is flagged with `donations:mark-test`.
        DB::table('transactions')
            ->where('gateway', 'stripe')
            ->where('gateway_reference', 'like', 'cs_test_%')
            ->update(['is_test' => true]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['is_test']);
            $table->dropColumn('is_test');
        });

        Schema::table('pledges', function (Blueprint $table) {
            $table->dropIndex(['is_test']);
            $table->dropColumn('is_test');
        });
    }
};
