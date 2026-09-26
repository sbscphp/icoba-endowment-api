<?php

namespace App\Console\Commands;

use App\Enums\PaymentGateway;
use App\Services\Maintenance\TransactionFailureService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class MarkTransactionsFailedCommand extends Command
{
    protected $signature = 'donations:mark-failed
                            {--transaction=* : Transaction UUID or TRN- id (repeatable)}
                            {--pending-before= : Every pending transaction created before this date/time (app timezone) or age, e.g. "2026-09-01", "48h", "7d"}
                            {--gateway=* : Only these gateways: stripe, paystack, fcmb (repeatable)}
                            {--include-bank-transfers : Let --pending-before also sweep offline bank transfers awaiting verification}
                            {--reason= : Why the transactions failed — stored in the transaction metadata}
                            {--force : Write the change (without it the command only previews)}
                            {--preview=50 : Max transactions to list}';

    protected $description = 'Mark pending transactions as failed when no gateway callback will do it (abandoned checkouts, dead references). Pending rows only.';

    // # Individual transactions
    // php artisan donations:mark-failed --transaction=TRN-ABC123XYZ789 --transaction=<uuid>
    // php artisan donations:mark-failed --transaction=TRN-ABC123XYZ789 --reason="Abandoned checkout" --force

    // # Every card/gateway checkout still pending after two days
    // php artisan donations:mark-failed --pending-before=48h
    // php artisan donations:mark-failed --pending-before=48h --gateway=paystack --force

    // # Including offline transfers that never arrived
    // php artisan donations:mark-failed --pending-before="2026-09-01" --include-bank-transfers --force

    public function handle(TransactionFailureService $service): int
    {
        $pendingBefore = $this->option('pending-before');
        $pendingBefore = is_string($pendingBefore) && trim($pendingBefore) !== '' ? trim($pendingBefore) : null;

        try {
            $pendingBefore = $pendingBefore !== null ? $this->parsePendingBefore($pendingBefore) : null;
        } catch (\Throwable) {
            $this->error('--pending-before is not a valid date or age (use e.g. "2026-09-01", "48h", "7d").');

            return self::FAILURE;
        }

        $gateways = array_map('strval', (array) $this->option('gateway'));
        $unknown = array_diff(array_map('strtolower', $gateways), PaymentGateway::values());
        if ($unknown !== []) {
            $this->error('Unknown --gateway: '.implode(', ', $unknown).'. Use one of: '.implode(', ', PaymentGateway::values()).'.');

            return self::FAILURE;
        }

        $dryRun = ! (bool) $this->option('force');
        $reason = $this->option('reason');

        if ($dryRun) {
            $this->info('Dry run — no records will be updated.');
        }

        try {
            $result = $service->markFailed([
                'transactions' => array_map('strval', (array) $this->option('transaction')),
                'pending_before' => $pendingBefore,
                'gateways' => $gateways,
                'include_bank_transfers' => (bool) $this->option('include-bank-transfers'),
                'reason' => is_string($reason) ? $reason : null,
            ], $dryRun, max(0, (int) $this->option('preview')));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['preview'] !== []) {
            $this->table(array_keys($result['preview'][0]), $result['preview']);
        }

        if ($result['not_found'] !== []) {
            $this->warn('Not found: '.implode(', ', $result['not_found']));
        }

        foreach ($result['skipped'] as $skipped) {
            $this->warn(sprintf('Skipped %s — already %s, only pending rows are changed.', $skipped['reference'], $skipped['status']));
        }

        $this->line(sprintf(
            '%s as failed — transactions: %d',
            $dryRun ? 'Would mark' : 'Marked',
            $dryRun ? $result['matched'] : $result['updated'],
        ));

        if ($dryRun) {
            $this->warn('Dry run complete. Re-run with --force to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Accepts an absolute date/time or a relative age such as "48h", "7d", "30m".
     */
    private function parsePendingBefore(string $value): CarbonInterface
    {
        if (preg_match('/^(\d+)\s*([mhdw])$/i', $value, $m) === 1) {
            $amount = (int) $m[1];

            return match (strtolower($m[2])) {
                'm' => now()->subMinutes($amount),
                'h' => now()->subHours($amount),
                'd' => now()->subDays($amount),
                'w' => now()->subWeeks($amount),
            };
        }

        return Date::parse($value);
    }
}
