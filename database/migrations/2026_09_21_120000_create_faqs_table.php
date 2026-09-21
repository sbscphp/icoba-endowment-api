<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->text('content');
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->uuid('created_by_admin_uuid')->nullable();
            $table->uuid('updated_by_admin_uuid')->nullable();
            $table->timestamps();

            $table->foreign('created_by_admin_uuid')
                ->references('uuid')
                ->on('admins')
                ->nullOnDelete();

            $table->foreign('updated_by_admin_uuid')
                ->references('uuid')
                ->on('admins')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
