<?php

namespace App\Services\Reconciliation;

use App\Exceptions\ApiException;
use Generator;
use Illuminate\Http\UploadedFile;

class FcmbCsvImportService
{
    public function __construct(
        private readonly BankFeedIngestionService $ingestionService,
    ) {}

    /**
     * @return array{processed: int, auto_matched: int, unmatched: int, skipped_duplicate: int, amount_mismatch: int}
     */
    public function importFromUploadedFile(UploadedFile $file): array
    {
        $stream = $file->getRealPath();
        if ($stream === false || $stream === '' || ! is_readable($stream)) {
            throw new ApiException('Uploaded statement file is not readable.', 422);
        }

        return $this->ingestionService->ingest($this->rowsFromCsv($stream));
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function rowsFromCsv(string $path): Generator
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new ApiException('Could not open statement file.', 500);
        }

        try {
            $headers = $this->readHeaders($handle);
            if ($headers === []) {
                return;
            }

            $columnMap = (array) config('fcmb_import.column_map', []);
            $lookup = $this->buildColumnIndex($headers, $columnMap);

            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $row === false) {
                    continue;
                }

                $debit = $this->valueAt($row, $lookup['debit'] ?? null);
                if ($debit !== null && $this->isNonZeroNumeric($debit)) {
                    continue;
                }

                $amount = $this->valueAt($row, $lookup['amount'] ?? null);
                if ($amount === null || ! $this->isNonZeroNumeric($amount)) {
                    continue;
                }

                yield [
                    'transaction_date' => $this->valueAt($row, $lookup['transaction_date'] ?? null),
                    'amount' => $amount,
                    'narration' => $this->valueAt($row, $lookup['narration'] ?? null) ?? '',
                    'statement_reference' => $this->valueAt($row, $lookup['statement_reference'] ?? null),
                    'account_number' => $this->valueAt($row, $lookup['account_number'] ?? null),
                    'source' => 'fcmb_import',
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return list<string>
     */
    private function readHeaders($handle): array
    {
        $headers = fgetcsv($handle);
        if ($headers === false || $headers === [null]) {
            return [];
        }

        return array_map(static fn ($value) => strtolower(trim((string) $value)), $headers);
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, string>  $map
     * @return array<string, int>
     */
    private function buildColumnIndex(array $headers, array $map): array
    {
        $index = [];
        foreach ($map as $field => $columnName) {
            $needle = strtolower(trim((string) $columnName));
            $position = array_search($needle, $headers, true);
            if ($position !== false) {
                $index[$field] = (int) $position;
            }
        }

        return $index;
    }

    /**
     * @param  list<mixed>  $row
     */
    private function valueAt(array $row, ?int $position): ?string
    {
        if ($position === null || ! array_key_exists($position, $row)) {
            return null;
        }

        $value = $row[$position];
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isNonZeroNumeric(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', $value);
        if ($cleaned === '' || $cleaned === null) {
            return false;
        }

        return (float) $cleaned > 0;
    }
}
