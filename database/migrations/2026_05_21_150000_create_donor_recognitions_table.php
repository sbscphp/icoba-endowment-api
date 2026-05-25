<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_recognitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('recognition_number', 32)->unique();
            $table->string('donor_key', 191)->index();
            $table->uuid('user_uuid')->nullable()->index();
            $table->string('donor_email', 191)->nullable()->index();
            $table->string('awardee_name', 255);
            $table->uuid('tier_uuid');
            $table->uuid('certificate_template_uuid')->nullable();
            $table->uuid('trigger_transaction_uuid')->nullable();
            $table->decimal('cumulative_amount_ngn', 18, 2);
            $table->decimal('initial_amount', 18, 2)->nullable();
            $table->string('initial_currency', 8)->nullable();
            $table->string('download_token', 64)->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('email_sent_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('tier_uuid')
                ->references('uuid')
                ->on('tier_configurations')
                ->restrictOnDelete();
            $table->foreign('certificate_template_uuid')
                ->references('uuid')
                ->on('certificate_templates')
                ->nullOnDelete();
            $table->foreign('trigger_transaction_uuid')
                ->references('uuid')
                ->on('transactions')
                ->nullOnDelete();

            $table->unique(['donor_key', 'tier_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_recognitions');
    }
};
