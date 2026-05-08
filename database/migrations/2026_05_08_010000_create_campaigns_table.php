<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('campaign_id', 32)->unique();
            $table->string('name', 60);
            $table->string('short_description', 500);
            $table->longText('long_description');
            $table->string('cover_image')->nullable();
            $table->json('gallery_images')->nullable();
            $table->json('categories');
            $table->string('base_currency', 8);
            $table->json('available_donation_currencies');
            $table->decimal('target_amount', 18, 2)->default(0);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamp('actual_start_date')->nullable();
            $table->timestamp('actual_end_date')->nullable();
            $table->boolean('allow_anonymous_donation')->default(false);
            $table->boolean('allow_public_donation')->default(false);
            $table->boolean('applies_to_all_graduation_sets')->default(true);
            $table->boolean('is_default')->default(false)->index();
            $table->string('status', 20);
            $table->uuid('created_by_admin_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_admin_uuid')->references('uuid')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
