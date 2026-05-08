<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_graduation_set', function (Blueprint $table) {
            $table->id();
            $table->uuid('campaign_uuid');
            $table->uuid('graduation_set_uuid');

            $table->foreign('campaign_uuid')->references('uuid')->on('campaigns')->cascadeOnDelete();
            $table->foreign('graduation_set_uuid')->references('uuid')->on('sets')->cascadeOnDelete();

            $table->unique(['campaign_uuid', 'graduation_set_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_graduation_set');
    }
};
