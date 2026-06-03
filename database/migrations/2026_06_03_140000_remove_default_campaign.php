<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('campaigns', 'is_default')) {
            DB::table('campaigns')->where('is_default', true)->delete();

            Schema::table('campaigns', function (Blueprint $table): void {
                $table->dropIndex(['is_default']);
                $table->dropColumn('is_default');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('campaigns', 'is_default')) {
            Schema::table('campaigns', function (Blueprint $table): void {
                $table->boolean('is_default')->default(false)->index();
            });
        }
    }
};
