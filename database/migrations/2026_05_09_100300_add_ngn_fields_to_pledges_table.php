<?php

use App\Enums\Currency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pledges', function (Blueprint $table): void {
            $table->decimal('exchange_rate_to_naira', 14, 6)->nullable()->after('currency');
            $table->decimal('committed_amount_ngn', 18, 2)->nullable()->after('exchange_rate_to_naira');
        });

        $ngn = Currency::NGN->value;
        DB::table('pledges')->whereRaw('UPPER(TRIM(currency)) = ?', [$ngn])->update([
            'committed_amount_ngn' => DB::raw('committed_amount'),
            'exchange_rate_to_naira' => 1,
        ]);
    }

    public function down(): void
    {
        Schema::table('pledges', function (Blueprint $table): void {
            $table->dropColumn(['exchange_rate_to_naira', 'committed_amount_ngn']);
        });
    }
};
