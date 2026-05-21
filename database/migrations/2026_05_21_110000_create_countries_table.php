<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->char('iso2', 2)->unique();
            $table->string('dial_code', 10)->comment('E.164 calling prefix e.g. +234');
            $table->string('flag_emoji', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index('dial_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
