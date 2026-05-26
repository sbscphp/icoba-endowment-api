<?php

namespace App\Services\Reconciliation;

use App\Enums\PaymentGateway;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Helpers\GeneralHelper;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bank\BankAccountRegistry;
use App\Services\Donation\BankTransferReferenceService;
use App\Services\Transaction\TransactionFinalizationService;
use App\Services\Transaction\TransactionNgnSnapshotService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Normalized row from any FCMB feed (CSV import, webhook, future API poll).
 *
 * - transaction_date: when the credit hit the ICOBA account
 * - amount: positive credit amount
 * - narration: free-text narration (we extract REF-... from here)
 * - statement_reference: bank's own row reference used for dedupe
 * - account_number: which ICOBA account received the funds (maps to currency)
 * - source: feed identifier — `fcmb_import` or `fcmb_webhook`
 */
final class BankFeedIngestionService
{
    public function __construct(
        private readonly BankAccountRegistry $bankAccountRegistry,
        private readonly BankTransferReferenceService $bankTransferReference,
        private readonly TransactionFinalizationService $finalizationService,
        private readonly TransactionNgnSnapshotService $transactionNgnSnapshot,
    ) {}

    /**
     * @param  iterable<array{
     *     transaction_date: string|CarbonInterface|null,
     *     amount: float|string,
     *     narration?: string|null,
     *     statement_reference?: string|null,
     *     account_number?: string|null,
     *     source?: string|null,
     * }>  $rows
     * @return array{processed: int, auto_matched: int, unmatched: int, skipped_duplicate: int, amount_mismatch: int}
     */
    public function ingest(iterable $rows, string $defaultSource = 'fcmb_import'): array
    {
        $summary = [
            'processed' => 0,
            'auto_matched' => 0,
            'unmatched' => 0,
            'skipped_duplicate' => 0,
            'amount_mismatch' => 0,
        ];

        foreach ($rows as $row) {
            $summary['processed']++;
            $result = $this->ingestRow($row, $defaultSource);
            if (isset($summary[$result])) {
                $summary[$result]++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function ingestRow(array $row, string $defaultSource): string
    {
        $statementReference = isset($row['statement_reference']) && trim((string) $row['statement_reference']) !== ''
            ? trim((string) $row['statement_reference'])
            : null;
        $source = isset($row['source']) && $row['source'] !== '' ? (string) $row['source'] : $defaultSource;

        if ($statementReference !== null) {
            $duplicate = Transaction::query()
                ->where('fcmb_statement_reference', $statementReference)
                ->first();
            if ($duplicate !== null) {
                return 'skipped_duplicate';
            }
        }

        $amount = $this->normalizeAmount($row['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            return 'unmatched';
        }

        $narration = isset($row['narration']) ? (string) $row['narration'] : '';
        $reference = $this->bankTransferReference->extractFromNarration($narration);

        $accountNumber = isset($row['account_number']) && $row['account_number'] !== ''
            ? trim((string) $row['account_number'])
            : null;
        $account = $accountNumber !== null
            ? $this->bankAccountRegistry->resolveByAccountNumber($accountNumber)
            : null;
        $rowCurrency = $account['currency'] ?? null;

        $transactionDate = $this->normalizeDate($row['transaction_date'] ?? null) ?? now();

        if ($reference !== null) {
            $pending = Transaction::query()
                ->where('bank_transfer_reference', $reference)
                ->where('status', TransactionStatus::PENDING)
                ->first();

            if ($pending !== null) {
                return $this->matchPending(
                    $pending,
                    amount: $amount,
                    narration: $narration,
                    statementReference: $statementReference,
                    accountNumber: $accountNumber,
                    rowCurrency: $rowCurrency,
                    paidAt: $transactionDate,
                    source: $source,
                );
            }
        }

        $this->createOrphan(
            amount: $amount,
            currency: $rowCurrency,
            narration: $narration,
            reference: $reference,
            statementReference: $statementReference,
            accountNumber: $accountNumber,
            account: $account,
            paidAt: $transactionDate,
            source: $source,
        );

        return 'unmatched';
    }

    private function matchPending(
        Transaction $pending,
        float $amount,
        string $narration,
        ?string $statementReference,
        ?string $accountNumber,
        ?string $rowCurrency,
        CarbonInterface $paidAt,
        string $source,
    ): string {
        $tolerance = $this->amountToleranceForCurrency((string) $pending->currency);
        $expected = (float) $pending->amount;
        $amountMismatch = abs($amount - $expected) > $tolerance;

        if ($accountNumber !== null
            && $pending->paid_into_account_number !== null
            && $pending->paid_into_account_number !== $accountNumber
        ) {
            $amountMismatch = true;
        }

        if ($amountMismatch) {
            DB::transaction(function () use ($pending, $amount, $narration, $statementReference, $accountNumber, $source, $paidAt): void {
                /** @var Transaction|null $locked */
                $locked = Transaction::query()
                    ->whereKey($pending->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    return;
                }

                $meta = is_array($locked->metadata) ? $locked->metadata : [];
                $meta['amount_mismatch'] = true;
                $meta['bank_narration'] = $narration;
                $meta['bank_transaction_date'] = $paidAt->toIso8601String();
                $meta['bank_amount'] = (string) $amount;
                $meta['source'] = $source;
                if ($statementReference !== null) {
                    $meta['fcmb_statement_reference'] = $statementReference;
                }

                $update = ['metadata' => $meta];
                if ($statementReference !== null && blank($locked->fcmb_statement_reference)) {
                    $update['fcmb_statement_reference'] = $statementReference;
                }
                if ($accountNumber !== null && blank($locked->paid_into_account_number)) {
                    $update['paid_into_account_number'] = $accountNumber;
                }

                $locked->forceFill($update)->save();
            });

            return 'amount_mismatch';
        }

        $this->finalizationService->finalizeSuccessful($pending, [
            'fcmb_statement_reference' => $statementReference,
            'paid_into_account_number' => $accountNumber ?? $pending->paid_into_account_number,
            'paid_at' => $paidAt,
            'ngn_rate_date' => $paidAt,
            'metadata' => [
                'source' => $source,
                'bank_narration' => $narration,
                'bank_transaction_date' => $paidAt->toIso8601String(),
            ],
            'tax_receipt_email_meta_key' => 'bank_transfer_tax_receipt_email_queued',
        ]);

        return 'auto_matched';
    }

    /**
     * @param  array{account_key: ?string, currency: string, currency_symbol: string, account_number: string}|null  $account
     */
    private function createOrphan(
        float $amount,
        ?string $currency,
        string $narration,
        ?string $reference,
        ?string $statementReference,
        ?string $accountNumber,
        ?array $account,
        CarbonInterface $paidAt,
        string $source,
    ): Transaction {
        $resolvedCurrency = $currency ?? 'NGN';

        $snapshot = $this->transactionNgnSnapshot->resolveAtDate($amount, $resolvedCurrency, $paidAt);

        $transactionId = GeneralHelper::getModelUniqueRandomId([
            'modelNamespace' => Transaction::class,
            'modelField' => 'transaction_id',
            'prefix' => 'TRN-',
            'idLength' => 12,
            'idType' => 'numalpha',
        ]);
        if (is_array($transactionId)) {
            $transactionId = 'TRN-'.strtoupper(bin2hex(random_bytes(4)));
        }

        $linkedUserUuid = $this->secondaryDonorLookup($narration);

        $metadata = [
            'source' => $source,
            'narration' => $narration,
            'bank_transaction_date' => $paidAt->toIso8601String(),
            'paid_into_account_number' => $accountNumber,
            'paid_into_account_key' => $account['account_key'] ?? null,
            'unmatched_reason' => $reference !== null
                ? 'reference_not_found'
                : 'no_reference_in_narration',
        ];

        $tx = Transaction::query()->create([
            'transaction_id' => $transactionId,
            'campaign_uuid' => null,
            'user_uuid' => $linkedUserUuid,
            'donor_name' => null,
            'amount' => $amount,
            'currency' => $resolvedCurrency,
            'exchange_rate_to_naira' => $snapshot['exchange_rate_to_naira'],
            'amount_in_naira' => $snapshot['amount_in_naira'],
            'status' => TransactionStatus::PENDING,
            'gateway' => PaymentGateway::Fcmb->value,
            'application_type' => TransactionApplicationType::BANK_TRANSFER,
            'paid_into_account_number' => $accountNumber,
            'fcmb_statement_reference' => $statementReference,
            'bank_transfer_reference' => $reference,
            'awaiting_bank_verification_at' => now(),
            'metadata' => $metadata,
        ]);

        return $tx;
    }

    private function secondaryDonorLookup(string $narration): ?string
    {
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $narration, $matches) === 1) {
            $email = strtolower($matches[0]);
            $user = User::query()->where('email', $email)->first(['uuid']);
            if ($user !== null) {
                return $user->uuid;
            }
        }

        if (preg_match('/\+?\d{10,15}/', $narration, $matches) === 1) {
            $phone = $matches[0];
            $user = User::query()->where('phone_number', $phone)->first(['uuid']);
            if ($user !== null) {
                return $user->uuid;
            }
        }

        return null;
    }

    private function amountToleranceForCurrency(string $currency): float
    {
        $config = (array) config('fcmb_import.amount_tolerance', []);
        $value = $config[strtoupper($currency)] ?? 0.0;

        return (float) $value;
    }

    private function normalizeAmount(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            return (float) $raw;
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $raw);
        if ($cleaned === '' || $cleaned === null) {
            return null;
        }

        return (float) $cleaned;
    }

    private function normalizeDate(mixed $raw): ?CarbonInterface
    {
        if ($raw instanceof CarbonInterface) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($raw));
        } catch (\Throwable $e) {
            Log::warning('BankFeedIngestionService: unable to parse transaction date.', [
                'value' => $raw,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
