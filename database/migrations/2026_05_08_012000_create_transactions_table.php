<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('transaction_id', 48)->unique();
            $table->uuid('campaign_uuid');
            $table->uuid('user_uuid')->nullable();
            $table->string('donor_name')->nullable();
            $table->string('donor_email')->nullable();
            $table->string('donor_phone', 32)->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 8);
            $table->decimal('exchange_rate_to_naira', 14, 6)->nullable();
            $table->decimal('amount_in_naira', 18, 2)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('gateway')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('campaign_uuid')->references('uuid')->on('campaigns')->restrictOnDelete();
            $table->foreign('user_uuid')->references('uuid')->on('users')->nullOnDelete();

            // Common filtering
            $table->index('status');
            $table->index('currency');
            $table->index('gateway');
            $table->index('paid_at');
            $table->index('created_at');
            $table->index('deleted_at');

            // Search / donor history
            $table->index('donor_email');
            $table->index('donor_phone');

            // Payment verification lookups
            $table->index('gateway_reference');

            // Dashboard / reporting queries
            $table->index(['campaign_uuid', 'status']);
            $table->index(['campaign_uuid', 'paid_at']);
            $table->index(['user_uuid', 'status']);
            $table->index(['status', 'paid_at']);

            // Currency analytics
            $table->index(['currency', 'status']);

            // Anonymous donations filtering
            $table->index('is_anonymous');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
