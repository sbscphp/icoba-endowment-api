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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('currency', 8);
            $table->decimal('rate_to_naira', 14, 6);
            $table->date('effective_date');
            $table->string('source', 64)->nullable();
            $table->timestamps();

            $table->unique(['currency', 'effective_date']);
            $table->index('currency');
            $table->index('effective_date');
        });

        $today = now()->toDateString();
        $rows = [];
        foreach (Currency::cases() as $currency) {
            $rows[] = [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'currency' => $currency->value,
                'rate_to_naira' => $currency->referenceNairaRatePerUnit(),
                'effective_date' => $today,
                'source' => 'seed_currency_enum',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('exchange_rates')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
