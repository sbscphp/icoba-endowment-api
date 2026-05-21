<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('receipt_number', 32)->nullable()->unique()->after('receipt_token');
        });

        DB::table('transactions')
            ->whereNotNull('paid_at')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $row): void {
                $paidAt = $row->paid_at ?? $row->created_at ?? now()->toDateTimeString();
                $year = date('Y', strtotime((string) $paidAt));

                DB::table('transactions')
                    ->where('id', $row->id)
                    ->update([
                        'receipt_number' => sprintf('ICOBA-%s-%06d', $year, (int) $row->id),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->dropColumn('receipt_number');
        });
    }
};
