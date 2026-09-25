<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('wives_type', 40)->nullable()->after('house')
                ->comment('Wives of ICOBA chapter slug (icobana_wives, wives_of_icoba_europe, wives_of_icoba_international)');
        });

        Schema::table('giving_identities', function (Blueprint $table): void {
            $table->string('wives_type', 40)->nullable()->after('house');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('wives_type');
        });

        Schema::table('giving_identities', function (Blueprint $table): void {
            $table->dropColumn('wives_type');
        });
    }
};
