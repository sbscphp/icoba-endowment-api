<?php

use App\Models\TierConfiguration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tier_configurations', function (Blueprint $table) {
            $table->string('slug', 120)->nullable()->unique()->after('name');
            $table->string('base_color', 7)->nullable()->after('tier_badge_url');
        });

        TierConfiguration::query()->orderBy('id')->each(function (TierConfiguration $tier): void {
            $baseSlug = Str::slug((string) $tier->name);
            $slug = $baseSlug !== '' ? $baseSlug : 'tier-'.$tier->id;
            $suffix = 1;

            while (
                DB::table('tier_configurations')
                    ->where('slug', $slug)
                    ->where('id', '!=', $tier->id)
                    ->exists()
            ) {
                $slug = $baseSlug.'-'.$suffix;
                $suffix++;
            }

            DB::table('tier_configurations')
                ->where('id', $tier->id)
                ->update(['slug' => $slug]);
        });
    }

    public function down(): void
    {
        Schema::table('tier_configurations', function (Blueprint $table) {
            $table->dropColumn(['slug', 'base_color']);
        });
    }
};
