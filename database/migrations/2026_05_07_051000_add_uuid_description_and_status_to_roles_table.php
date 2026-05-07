<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id')->index();
            $table->string('description')->nullable()->after('name');
            $table->boolean('is_active')->default(true)->after('guard_name')->index();
        });

        DB::table('roles')
            ->whereNull('uuid')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $role): void {
                DB::table('roles')
                    ->where('id', $role->id)
                    ->update(['uuid' => (string) Str::uuid()]);
            });

        Schema::table('roles', function (Blueprint $table) {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'description', 'is_active']);
        });
    }
};
