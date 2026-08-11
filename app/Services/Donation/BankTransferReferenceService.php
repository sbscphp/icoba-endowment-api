<?php

namespace App\Services\Donation;

use App\Helpers\GeneralHelper;
use App\Models\Transaction;

class BankTransferReferenceService
{
    public const PREFIX = 'ICB-ENMT-REF-';

    public const PATTERN = '/ICB-ENMT-REF-[A-Za-z0-9]{6,12}/';

    /**
     * Generate a unique narration reference (e.g. ICB-ENMT-REF-82Re93GHA).
     */
    public function generateUniqueReference(int $length = 10): string
    {
        $generated = GeneralHelper::getModelUniqueRandomId([
            'modelNamespace' => Transaction::class,
            'modelField' => 'bank_transfer_reference',
            'prefix' => self::PREFIX,
            'idLength' => $length,
            'idType' => 'numalpha',
        ]);

        if (is_string($generated)) {
            return $generated;
        }

        return self::PREFIX.strtoupper(bin2hex(random_bytes(5)));
    }

    /**
     * Extract the first bank-transfer reference found in a narration string.
     */
    public function extractFromNarration(?string $narration): ?string
    {
        if ($narration === null || trim($narration) === '') {
            return null;
        }

        if (preg_match(self::PATTERN, $narration, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }
}
