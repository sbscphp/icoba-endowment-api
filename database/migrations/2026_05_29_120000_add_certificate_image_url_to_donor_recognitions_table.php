<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donor_recognitions', function (Blueprint $table) {
            $table->string('certificate_image_url', 500)->nullable()->after('download_token');
        });
    }

    public function down(): void
    {
        Schema::table('donor_recognitions', function (Blueprint $table) {
            $table->dropColumn('certificate_image_url');
        });
    }
};
