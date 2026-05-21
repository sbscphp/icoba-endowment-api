<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('transaction_uuid');
            $table->string('tier_label')->nullable();
            $table->uuid('tier_uuid')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->foreign('transaction_uuid')->references('uuid')->on('transactions')->cascadeOnDelete();
            $table->unique('transaction_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_receipts');
    }
};
