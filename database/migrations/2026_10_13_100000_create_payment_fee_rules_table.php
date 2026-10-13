<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service fee bands per currency. One active band covers a donation amount; the fee is
 * amount × percentage + fixed, capped. Which gateway the donor picks does not matter:
 * the gateway's own cost is subtracted afterwards (transactions.gateway_fee_amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_fee_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->string('currency', 8);
            $table->decimal('min_amount', 18, 2)->default(0);
            $table->decimal('max_amount', 18, 2)->nullable();
            // 3.0000 means 3 percent of the donation amount
            $table->decimal('percentage', 7, 4)->default(0);
            $table->decimal('fixed_amount', 18, 2)->default(0);
            $table->decimal('cap_amount', 18, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['currency', 'is_active'], 'payment_fee_rules_lookup_idx');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_fee_rules');
    }
};
