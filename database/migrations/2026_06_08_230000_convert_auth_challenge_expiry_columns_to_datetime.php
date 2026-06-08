<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auth_challenges')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        // TIMESTAMP applies MySQL session timezone on write/read; DATETIME stores the literal value.
        DB::statement('ALTER TABLE auth_challenges MODIFY expires_at DATETIME NOT NULL');
        DB::statement('ALTER TABLE auth_challenges MODIFY used_at DATETIME NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('auth_challenges')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE auth_challenges MODIFY expires_at TIMESTAMP NOT NULL');
        DB::statement('ALTER TABLE auth_challenges MODIFY used_at TIMESTAMP NULL');
    }
};
