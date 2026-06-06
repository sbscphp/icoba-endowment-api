<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giving_identities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('email_lower', 190)->nullable()->unique();
            $table->uuid('user_uuid')->nullable()->unique();
            $table->uuid('donor_type_uuid')->nullable();
            $table->uuid('graduation_set_uuid')->nullable();
            $table->uuid('corporate_category_uuid')->nullable();
            $table->string('organization_name', 150)->nullable();
            $table->string('rc_number', 64)->nullable();
            $table->string('tin', 64)->nullable();
            $table->string('firstname', 50)->nullable();
            $table->string('lastname', 50)->nullable();
            $table->string('alumni_identifier', 50)->nullable();
            $table->string('status', 32)->default('unverified');
            $table->string('source', 32)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->foreign('user_uuid')->references('uuid')->on('users')->nullOnDelete();
            $table->foreign('donor_type_uuid')->references('uuid')->on('donor_types')->nullOnDelete();
            $table->foreign('graduation_set_uuid')->references('uuid')->on('sets')->nullOnDelete();
            $table->foreign('corporate_category_uuid')->references('uuid')->on('corporate_categories')->nullOnDelete();

            $table->index('status');
            $table->index('locked_at');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->uuid('giving_identity_uuid')->nullable()->after('user_uuid');
            $table->foreign('giving_identity_uuid')->references('uuid')->on('giving_identities')->nullOnDelete();
            $table->index('giving_identity_uuid');
        });

        Schema::table('pledges', function (Blueprint $table): void {
            $table->uuid('giving_identity_uuid')->nullable()->after('user_uuid');
            $table->foreign('giving_identity_uuid')->references('uuid')->on('giving_identities')->nullOnDelete();
            $table->index('giving_identity_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('pledges', function (Blueprint $table): void {
            $table->dropForeign(['giving_identity_uuid']);
            $table->dropColumn('giving_identity_uuid');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropForeign(['giving_identity_uuid']);
            $table->dropColumn('giving_identity_uuid');
        });

        Schema::dropIfExists('giving_identities');
    }
};
