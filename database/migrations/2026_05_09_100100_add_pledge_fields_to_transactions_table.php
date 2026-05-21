<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->uuid('pledge_uuid')->nullable()->after('user_uuid');
            $table->string('application_type', 48)->nullable()->after('is_anonymous');
            $table->uuid('superseded_by_transaction_uuid')->nullable()->after('gateway_reference');
            $table->uuid('donor_type_uuid')->nullable()->after('donor_phone');
            $table->string('receipt_token', 64)->nullable()->unique()->after('metadata');

            $table->foreign('pledge_uuid')->references('uuid')->on('pledges')->nullOnDelete();
            $table->foreign('superseded_by_transaction_uuid')->references('uuid')->on('transactions')->nullOnDelete();
            $table->foreign('donor_type_uuid')->references('uuid')->on('donor_types')->nullOnDelete();

            $table->index('pledge_uuid');
            $table->index('application_type');
            $table->index('receipt_token');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['pledge_uuid']);
            $table->dropForeign(['superseded_by_transaction_uuid']);
            $table->dropForeign(['donor_type_uuid']);

            $table->dropIndex(['pledge_uuid']);
            $table->dropIndex(['application_type']);
            $table->dropUnique(['receipt_token']);

            $table->dropColumn([
                'pledge_uuid',
                'application_type',
                'superseded_by_transaction_uuid',
                'donor_type_uuid',
                'receipt_token',
            ]);
        });
    }
};
