<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('bank_transfer_reference', 48)->nullable()->unique()->after('gateway_reference');
            $table->string('paid_into_account_number', 64)->nullable()->after('bank_transfer_reference');
            $table->string('fcmb_statement_reference', 128)->nullable()->after('paid_into_account_number');
            $table->timestamp('awaiting_bank_verification_at')->nullable()->after('paid_at');
            $table->timestamp('reconciled_at')->nullable()->after('awaiting_bank_verification_at');
            $table->uuid('reconciled_by_admin_uuid')->nullable()->after('reconciled_at');
            $table->text('reconciliation_note')->nullable()->after('reconciled_by_admin_uuid');

            $table->index('paid_into_account_number');
            $table->index('fcmb_statement_reference');
            $table->index('awaiting_bank_verification_at');
            $table->index('reconciled_at');
            $table->index('reconciled_by_admin_uuid');

            $table->foreign('reconciled_by_admin_uuid')
                ->references('uuid')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['reconciled_by_admin_uuid']);

            $table->dropUnique(['bank_transfer_reference']);
            $table->dropIndex(['paid_into_account_number']);
            $table->dropIndex(['fcmb_statement_reference']);
            $table->dropIndex(['awaiting_bank_verification_at']);
            $table->dropIndex(['reconciled_at']);
            $table->dropIndex(['reconciled_by_admin_uuid']);

            $table->dropColumn([
                'bank_transfer_reference',
                'paid_into_account_number',
                'fcmb_statement_reference',
                'awaiting_bank_verification_at',
                'reconciled_at',
                'reconciled_by_admin_uuid',
                'reconciliation_note',
            ]);
        });
    }
};
