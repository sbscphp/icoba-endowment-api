<?php

namespace App\Console\Commands;

use App\Enums\GivingIdentityStatus;
use App\Models\GivingIdentity;
use App\Services\GivingIdentity\GivingIdentityConflictAnalyzer;
use App\Services\GivingIdentity\GivingIdentityConflictResolver;
use Illuminate\Console\Command;

class ReportGivingIdentityConflictsCommand extends Command
{
    protected $signature = 'giving-identities:report-conflicts
                            {--email= : Report a single donor email only}
                            {--status=conflict : Filter identities by status (conflict, all)}
                            {--json : Output machine-readable JSON}
                            {--resolve : Resolve conflicts by aligning records to the tied giving identity}
                            {--dry-run : With --resolve, preview changes without writing}';

    protected $description = 'Report giving identity profile conflicts and optionally resolve them.';

    public function __construct(
        private readonly GivingIdentityConflictAnalyzer $analyzer,
        private readonly GivingIdentityConflictResolver $conflictResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $emailFilter = is_string($this->option('email')) ? trim($this->option('email')) : null;
        $statusFilter = strtolower(trim((string) $this->option('status')));
        $asJson = (bool) $this->option('json');
        $resolve = (bool) $this->option('resolve');
        $dryRun = (bool) $this->option('dry-run');

        if ($resolve) {
            return $this->handleResolve($emailFilter, $dryRun, $asJson);
        }

        $emails = $this->emailsToReport($emailFilter, $statusFilter);
        $reports = $emails
            ->map(fn (string $email): array => $this->analyzer->analyzeEmail($email))
            ->filter(fn (array $report): bool => $report['has_conflict'] || $statusFilter === 'all')
            ->values();

        if ($asJson) {
            $this->line(json_encode($reports->map(fn (array $report): array => $this->serializeReport($report))->all(), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($reports->isEmpty()) {
            $this->info('No giving identity conflicts found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d email(s) with profile conflicts.', $reports->filter(fn (array $r): bool => $r['has_conflict'])->count()));
        $this->newLine();

        foreach ($reports as $report) {
            $this->renderReport($report);
            $this->newLine();
        }

        $this->comment('Resolve all: php artisan giving-identities:report-conflicts --resolve');
        $this->comment('Resolve one:  php artisan giving-identities:report-conflicts --email=user@example.com --resolve');
        $this->comment('Preview:      php artisan giving-identities:report-conflicts --resolve --dry-run');

        return self::SUCCESS;
    }

    private function handleResolve(?string $emailFilter, bool $dryRun, bool $asJson): int
    {
        $identities = GivingIdentity::query()
            ->when($emailFilter !== null && $emailFilter !== '', function ($query) use ($emailFilter): void {
                $query->whereRaw('LOWER(TRIM(email_lower)) = ?', [strtolower(trim($emailFilter))]);
            })
            ->when($emailFilter === null || $emailFilter === '', fn ($query) => $query->where('status', GivingIdentityStatus::CONFLICT))
            ->get();

        if ($identities->isEmpty()) {
            $this->info('No giving identities matched for resolution.');

            return self::SUCCESS;
        }

        $results = [];
        $totalTransactions = 0;
        $totalPledges = 0;

        foreach ($identities as $identity) {
            $result = $this->conflictResolver->resolveForIdentity($identity, $dryRun);
            $result['email'] = $identity->email_lower;
            $result['identity_uuid'] = $identity->uuid;
            $results[] = $result;
            $totalTransactions += $result['transactions'];
            $totalPledges += $result['pledges'];

            if (! $asJson) {
                $prefix = $dryRun ? '[DRY RUN] Would align' : 'Aligned';
                $this->line("{$prefix} {$identity->email_lower}: {$result['transactions']} transaction(s), {$result['pledges']} pledge(s).");
            }
        }

        if ($asJson) {
            $this->line(json_encode([
                'dry_run' => $dryRun,
                'results' => $results,
                'totals' => [
                    'transactions' => $totalTransactions,
                    'pledges' => $totalPledges,
                    'identities' => count($results),
                ],
            ], JSON_PRETTY_PRINT));
        } else {
            $this->newLine();
            $this->table(['Metric', 'Count'], [
                ['Identities processed', (string) count($results)],
                ['Transactions aligned', (string) $totalTransactions],
                ['Pledges aligned', (string) $totalPledges],
            ]);

            if ($dryRun) {
                $this->warn('Dry run complete. Re-run with --resolve (without --dry-run) to apply.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function emailsToReport(?string $emailFilter, string $statusFilter)
    {
        if ($emailFilter !== null && $emailFilter !== '') {
            return $this->analyzer->collectDistinctEmails($emailFilter);
        }

        if ($statusFilter === 'all') {
            return $this->analyzer->collectDistinctEmails();
        }

        return GivingIdentity::query()
            ->where('status', GivingIdentityStatus::CONFLICT)
            ->whereNotNull('email_lower')
            ->orderBy('email_lower')
            ->pluck('email_lower');
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $status = $report['identity_status'] ?? 'none';
        $this->line('<fg=cyan>'.$report['email'].'</>  status='.$status.'  identity='.($report['identity_uuid'] ?? 'none'));

        if ($report['canonical'] !== null) {
            $this->line('  <fg=green>Canonical (tied identity):</> '.$this->analyzer->formatProfileSummary($report['canonical']));
        }

        if ($report['conflicting_profiles']->isEmpty()) {
            $this->line('  <fg=gray>No conflicting observed profiles.</>');

            return;
        }

        $this->line('  <fg=yellow>Conflicting observed profile(s):</>');
        foreach ($report['conflicting_profiles'] as $index => $profile) {
            $this->line('    '.($index + 1).'. '.$this->analyzer->formatProfileSummary($profile));
        }

        $this->line(sprintf(
            '  Records: %d transaction(s), %d pledge(s)',
            $report['transaction_count'],
            $report['pledge_count'],
        ));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function serializeReport(array $report): array
    {
        return [
            'email' => $report['email'],
            'identity_uuid' => $report['identity_uuid'],
            'identity_status' => $report['identity_status'],
            'user_uuid' => $report['user_uuid'],
            'has_conflict' => $report['has_conflict'],
            'transaction_count' => $report['transaction_count'],
            'pledge_count' => $report['pledge_count'],
            'canonical' => $report['canonical'] !== null
                ? $this->analyzer->formatProfileSummary($report['canonical'])
                : null,
            'observed_profiles' => $report['observed_profiles']
                ->map(fn ($profile) => $this->analyzer->formatProfileSummary($profile))
                ->values()
                ->all(),
            'conflicting_profiles' => $report['conflicting_profiles']
                ->map(fn ($profile) => $this->analyzer->formatProfileSummary($profile))
                ->values()
                ->all(),
        ];
    }
}
