<?php

use App\Enums\IssuedCertificateStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donor_recognitions', function (Blueprint $table) {
            $table->string('status', 32)
                ->default(IssuedCertificateStatus::AUTO_ISSUED->value)
                ->after('issued_at')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('donor_recognitions', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
