<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('campaigns', 'is_default')) {
            return;
        }

        $defaultCampaignUuids = DB::table('campaigns')
            ->where('is_default', true)
            ->pluck('uuid')
            ->all();

        if ($defaultCampaignUuids !== []) {
            // Order matters: the two RESTRICT FKs (pledges.campaign_uuid,
            // transactions.campaign_uuid) block deleting the campaign row,
            // so we clear them first. Remaining tables (campaign_emails,
            // campaign_status_logs, campaign_graduation_set,
            // campaign_update_reports) cascade automatically.
            //
            // Side-effects worth knowing:
            //   - transactions on OTHER campaigns whose pledge_uuid pointed
            //     at a default-campaign pledge will have pledge_uuid set to
            //     NULL (FK: nullOnDelete). Transaction history is retained.
            //   - donor_recognitions.trigger_transaction_uuid and
            //     transactions.superseded_by_transaction_uuid are nulled
            //     when the referenced transaction is deleted.
            //   - transaction_receipts cascade-delete with their transaction.
            DB::table('transactions')
                ->whereIn('campaign_uuid', $defaultCampaignUuids)
                ->delete();

            DB::table('pledges')
                ->whereIn('campaign_uuid', $defaultCampaignUuids)
                ->delete();

            DB::table('campaigns')
                ->whereIn('uuid', $defaultCampaignUuids)
                ->delete();
        }

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropIndex(['is_default']);
            $table->dropColumn('is_default');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('campaigns', 'is_default')) {
            Schema::table('campaigns', function (Blueprint $table): void {
                $table->boolean('is_default')->default(false)->index();
            });
        }
    }
};
