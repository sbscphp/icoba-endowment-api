<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('status')->index();
        });

        DB::table('contact_submissions')
            ->where('status', 'resolved')
            ->whereNull('resolved_at')
            ->update(['resolved_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropColumn('resolved_at');
        });
    }
};
