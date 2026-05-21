<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes omitted from earlier migrations but needed for common filters,
     * soft deletes, sorting, and reporting queries.
     *
     * @var array<string, list<array{columns: list<string>, name: string}>>
     */
    private const INDEXES = [
        'campaigns' => [
            ['columns' => ['status'], 'name' => 'cmp_status_idx'],
            ['columns' => ['deleted_at'], 'name' => 'cmp_del_idx'],
            ['columns' => ['created_at'], 'name' => 'cmp_cr_idx'],
            ['columns' => ['status', 'deleted_at'], 'name' => 'cmp_stat_del_idx'],
        ],
        'campaign_emails' => [
            ['columns' => ['status'], 'name' => 'ce_status_idx'],
            ['columns' => ['deleted_at'], 'name' => 'ce_del_idx'],
            ['columns' => ['created_at'], 'name' => 'ce_cr_idx'],
            ['columns' => ['updated_at'], 'name' => 'ce_upd_idx'],
            ['columns' => ['sent_at'], 'name' => 'ce_sent_idx'],
            ['columns' => ['campaign_uuid', 'status'], 'name' => 'ce_cmp_stat_idx'],
        ],
        'campaign_status_logs' => [
            ['columns' => ['created_at'], 'name' => 'csl_cr_idx'],
            ['columns' => ['campaign_uuid', 'created_at'], 'name' => 'csl_cmp_cr_idx'],
        ],
        'pledges' => [
            ['columns' => ['deleted_at'], 'name' => 'plg_del_idx'],
            ['columns' => ['created_at'], 'name' => 'plg_cr_idx'],
            ['columns' => ['currency'], 'name' => 'plg_curr_idx'],
            ['columns' => ['is_anonymous'], 'name' => 'plg_anon_idx'],
            ['columns' => ['payment_plan_type'], 'name' => 'plg_plan_idx'],
        ],
        'transactions' => [
            ['columns' => ['pledge_uuid', 'status'], 'name' => 'txn_plg_stat_idx'],
        ],
        'audit_logs' => [
            ['columns' => ['created_at'], 'name' => 'aud_cr_idx'],
        ],
        'one_time_passwords' => [
            ['columns' => ['expires_at'], 'name' => 'otp_exp_idx'],
            ['columns' => ['used'], 'name' => 'otp_used_idx'],
            ['columns' => ['user_id', 'purpose', 'used'], 'name' => 'otp_usr_pur_used_idx'],
        ],
        'tier_configurations' => [
            ['columns' => ['is_active', 'min_amount'], 'name' => 'tier_act_min_idx'],
        ],
        'themes' => [
            ['columns' => ['is_default'], 'name' => 'thm_def_idx'],
        ],
        'notifications' => [
            ['columns' => ['read_at'], 'name' => 'ntf_read_idx'],
            ['columns' => ['notifiable_type', 'notifiable_id', 'created_at'], 'name' => 'ntf_nbl_cr_idx'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $index) {
                $this->addIndex($table, $index['columns'], $index['name']);
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $index) {
                $this->dropIndex($table, $index['name']);
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndex(string $table, array $columns, string $name): void
    {
        if ($this->hasIndex($table, $name) || $this->hasIndexOnColumns($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! $this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        return in_array($name, $this->indexNames($table), true);
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexOnColumns(string $table, array $columns): bool
    {
        $target = array_values($columns);

        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => array_values($index['columns']) === $target);
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        return collect(Schema::getIndexes($table))
            ->pluck('name')
            ->all();
    }
};
