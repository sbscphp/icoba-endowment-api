<?php

use App\Enums\DonorTypeSlug;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        DB::table('donor_types')
            ->where('slug', 'friends_relatives')
            ->update([
                'slug' => DonorTypeSlug::FRIENDS_OF_ICOBA->value,
                'label' => DonorTypeSlug::FRIENDS_OF_ICOBA->label(),
                'description' => DonorTypeSlug::FRIENDS_OF_ICOBA->description(),
                'updated_at' => $now,
            ]);

        if (! DB::table('donor_types')->where('slug', DonorTypeSlug::RELATIVES_OF_ICOBA->value)->exists()) {
            DB::table('donor_types')->insert([
                'id' => 4,
                'uuid' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'donor-type:'.DonorTypeSlug::RELATIVES_OF_ICOBA->value)->toString(),
                'slug' => DonorTypeSlug::RELATIVES_OF_ICOBA->value,
                'label' => DonorTypeSlug::RELATIVES_OF_ICOBA->label(),
                'description' => DonorTypeSlug::RELATIVES_OF_ICOBA->description(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $now = Carbon::now();

        DB::table('donor_types')
            ->where('slug', DonorTypeSlug::RELATIVES_OF_ICOBA->value)
            ->delete();

        DB::table('donor_types')
            ->where('slug', DonorTypeSlug::FRIENDS_OF_ICOBA->value)
            ->update([
                'slug' => 'friends_relatives',
                'label' => 'Friends & Relatives of ICOBA',
                'description' => 'Donate to Igbobi College as a friend or relative to an Igbobi college alumnus.',
                'updated_at' => $now,
            ]);
    }
};
