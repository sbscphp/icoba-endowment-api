<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_document_download_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('token', 64)->unique();
            $table->string('document_type', 64);
            $table->uuid('subject_uuid');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['document_type', 'subject_uuid']);
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_document_download_tokens');
    }
};
