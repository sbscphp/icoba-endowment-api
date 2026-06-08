<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('narration', 1000)->nullable()->after('fcmb_statement_reference');
        });

        DB::table('transactions')
            ->whereNull('narration')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $metadata = json_decode((string) $row->metadata, true);
                    if (! is_array($metadata)) {
                        continue;
                    }

                    $narration = $metadata['narration'] ?? $metadata['bank_narration'] ?? null;
                    if (! is_string($narration) || trim($narration) === '') {
                        continue;
                    }

                    DB::table('transactions')
                        ->where('id', $row->id)
                        ->update(['narration' => mb_substr(trim($narration), 0, 1000)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('narration');
        });
    }
};
