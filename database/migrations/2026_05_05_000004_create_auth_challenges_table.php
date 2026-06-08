<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->string('purpose');
            $table->string('channel')->default('email');
            $table->string('code_hash');
            $table->dateTime('expires_at')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('used_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'purpose']);
            $table->index('used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_challenges');
    }
};
