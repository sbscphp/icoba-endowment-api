<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service fee columns. `amount` stays the donation and is never touched by the fee feature:
 *
 *   fee_amount              service fee charged on top (from the matched payment_fee_rule)
 *   total_amount            amount + fee_amount (what the gateway is asked to charge)
 *   fee_amount_in_naira     fee_amount at the same NGN rate as amount_in_naira
 *   fee_rule_uuid           rule applied at intent time
 *   fee_snapshot            frozen copy of that rule
 *   gateway_amount_received what the gateway reported it collected (webhook / verify)
 *   gateway_fee_amount      what the gateway reported it kept; margin = fee_amount - gateway_fee_amount
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('fee_amount', 18, 2)->default(0)->after('amount');
            $table->decimal('total_amount', 18, 2)->nullable()->after('fee_amount');
            $table->decimal('fee_amount_in_naira', 18, 2)->nullable()->after('amount_in_naira');
            $table->uuid('fee_rule_uuid')->nullable()->after('fee_amount_in_naira');
            $table->json('fee_snapshot')->nullable()->after('fee_rule_uuid');
            $table->decimal('gateway_amount_received', 18, 2)->nullable()->after('fee_snapshot');
            $table->decimal('gateway_fee_amount', 18, 2)->nullable()->after('gateway_amount_received');

            $table->foreign('fee_rule_uuid')->references('uuid')->on('payment_fee_rules')->nullOnDelete();
            $table->index('total_amount');
        });

        // Existing rows carried no fee: the charged figure was the donation itself.
        DB::table('transactions')->whereNull('total_amount')->update([
            'total_amount' => DB::raw('amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['fee_rule_uuid']);
            $table->dropIndex(['total_amount']);
            $table->dropColumn([
                'fee_amount',
                'total_amount',
                'fee_amount_in_naira',
                'fee_rule_uuid',
                'fee_snapshot',
                'gateway_amount_received',
                'gateway_fee_amount',
            ]);
        });
    }
};
