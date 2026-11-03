<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_images', function (Blueprint $table) {
            // Every image already uploaded was shown in the (only) landscape slot, so that is the default.
            $table->string('orientation', 20)->default('landscape')->after('ad_uuid');

            $table->index(['ad_uuid', 'orientation', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('ad_images', function (Blueprint $table) {
            $table->dropIndex(['ad_uuid', 'orientation', 'sort_order']);
            $table->dropColumn('orientation');
        });
    }
};
