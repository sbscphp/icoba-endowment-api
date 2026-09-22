<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('house', 20)->nullable()->after('graduation_set_uuid')
                ->comment('Igbobi College house slug (alumni: own house; wives/corporates: affiliated Igbobian house)');
            $table->uuid('affiliated_graduation_set_uuid')->nullable()->after('house')
                ->comment('Set of the affiliated Igbobian (wives / Igbobian-owned corporates)');
            $table->boolean('is_igbobian_owned')->default(false)->after('affiliated_graduation_set_uuid')
                ->comment('Corporate donor belongs to an Igbobian');

            $table->index('house');
            $table->foreign('affiliated_graduation_set_uuid')->references('uuid')->on('sets')->nullOnDelete();
        });

        Schema::table('giving_identities', function (Blueprint $table) {
            $table->string('house', 20)->nullable()->after('graduation_set_uuid');
            $table->uuid('affiliated_graduation_set_uuid')->nullable()->after('house');
            $table->boolean('is_igbobian_owned')->default(false)->after('affiliated_graduation_set_uuid');

            $table->index('house');
            $table->foreign('affiliated_graduation_set_uuid')->references('uuid')->on('sets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('giving_identities', function (Blueprint $table) {
            $table->dropForeign(['affiliated_graduation_set_uuid']);
            $table->dropIndex(['house']);
            $table->dropColumn(['house', 'affiliated_graduation_set_uuid', 'is_igbobian_owned']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['affiliated_graduation_set_uuid']);
            $table->dropIndex(['house']);
            $table->dropColumn(['house', 'affiliated_graduation_set_uuid', 'is_igbobian_owned']);
        });
    }
};
