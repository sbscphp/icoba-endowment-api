<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120)->unique();
            $table->uuid('tier_uuid')->nullable()->index();
            $table->json('design');
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();

            $table->foreign('tier_uuid')
                ->references('uuid')
                ->on('tier_configurations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_templates');
    }
};
