<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pledges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('campaign_uuid');
            $table->uuid('user_uuid')->nullable();
            $table->uuid('donor_type_uuid')->nullable();
            $table->uuid('graduation_set_uuid')->nullable();
            $table->string('donor_name')->nullable();
            $table->string('donor_email')->nullable();
            $table->string('donor_phone', 32)->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->decimal('committed_amount', 18, 2);
            $table->string('currency', 8);
            $table->string('payment_plan_type', 32);
            $table->unsignedSmallInteger('installment_count')->nullable();
            $table->json('schedule')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('fulfilled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('campaign_uuid')->references('uuid')->on('campaigns')->restrictOnDelete();
            $table->foreign('user_uuid')->references('uuid')->on('users')->nullOnDelete();
            $table->foreign('donor_type_uuid')->references('uuid')->on('donor_types')->nullOnDelete();
            $table->foreign('graduation_set_uuid')->references('uuid')->on('sets')->nullOnDelete();

            $table->index(['campaign_uuid', 'status']);
            $table->index(['user_uuid', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pledges');
    }
};
