<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 64)->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('corporate_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('sets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('public_id', 32)->unique()->comment('Opaque identifier (legacy setId)');
            $table->string('name');
            $table->unsignedSmallInteger('start_year')->nullable();
            $table->unsignedSmallInteger('end_year')->nullable();
            $table->string('set_number', 16)->unique()->comment('Value validated on alumni registration');
            $table->uuid('admin_uuid')->nullable();
            $table->timestamps();

            $table->foreign('admin_uuid')->references('uuid')->on('admins')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('donor_type_uuid')->nullable()->after('uuid');
            $table->uuid('corporate_category_uuid')->nullable();
            $table->uuid('graduation_set_uuid')->nullable();
            $table->string('organization_name', 150)->nullable();
            $table->string('alumni_identifier', 50)->nullable()->comment('ICOBA alumni ID; optional at signup');

            $table->foreign('donor_type_uuid')->references('uuid')->on('donor_types')->nullOnDelete();
            $table->foreign('corporate_category_uuid')->references('uuid')->on('corporate_categories')->nullOnDelete();
            $table->foreign('graduation_set_uuid')->references('uuid')->on('sets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['donor_type_uuid']);
            $table->dropForeign(['corporate_category_uuid']);
            $table->dropForeign(['graduation_set_uuid']);
            $table->dropColumn([
                'donor_type_uuid',
                'corporate_category_uuid',
                'graduation_set_uuid',
                'organization_name',
                'alumni_identifier',
            ]);
        });

        Schema::dropIfExists('sets');
        Schema::dropIfExists('corporate_categories');
        Schema::dropIfExists('donor_types');
    }
};
