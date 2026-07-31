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

        if (! DB::table('donor_types')->where('slug', DonorTypeSlug::WIVES_OF_ICOBA->value)->exists()) {
            DB::table('donor_types')->insert([
                'id' => 5,
                'uuid' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'donor-type:'.DonorTypeSlug::WIVES_OF_ICOBA->value)->toString(),
                'slug' => DonorTypeSlug::WIVES_OF_ICOBA->value,
                'label' => DonorTypeSlug::WIVES_OF_ICOBA->label(),
                'description' => DonorTypeSlug::WIVES_OF_ICOBA->description(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('donor_types')
            ->where('slug', DonorTypeSlug::WIVES_OF_ICOBA->value)
            ->delete();
    }
};
