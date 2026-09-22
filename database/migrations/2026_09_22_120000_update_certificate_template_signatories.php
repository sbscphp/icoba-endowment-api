<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, array{name: string, position: string}>
     */
    private const REPLACEMENTS = [
        'Ayanwale Erikina' => ['name' => 'Kunle Elebute', 'position' => 'Chairman'],
        'Adekunle Modupeola' => ['name' => 'Olawale Abiola', 'position' => 'Secretary'],
    ];

    public function up(): void
    {
        $this->rewriteSignatories(self::REPLACEMENTS);
    }

    public function down(): void
    {
        $this->rewriteSignatories([
            'Kunle Elebute' => ['name' => 'Ayanwale Erikina', 'position' => 'Global President'],
            'Olawale Abiola' => ['name' => 'Adekunle Modupeola', 'position' => 'Head, Endowment Initiative'],
        ]);
    }

    /**
     * @param  array<string, array{name: string, position: string}>  $replacements
     */
    private function rewriteSignatories(array $replacements): void
    {
        foreach (DB::table('certificate_templates')->select(['id', 'design'])->cursor() as $row) {
            $design = json_decode((string) $row->design, true);

            if (! is_array($design) || ! is_array($design['signatories'] ?? null)) {
                continue;
            }

            $changed = false;

            foreach ($design['signatories'] as $index => $signatory) {
                if (! is_array($signatory)) {
                    continue;
                }

                $replacement = $replacements[$signatory['name'] ?? ''] ?? null;

                if ($replacement === null) {
                    continue;
                }

                $design['signatories'][$index]['name'] = $replacement['name'];
                $design['signatories'][$index]['position'] = $replacement['position'];
                // The stored signature image belongs to the previous signatory; clear it
                // so it is not shown above the new name until a new one is uploaded.
                $design['signatories'][$index]['signature_url'] = null;
                $changed = true;
            }

            if ($changed) {
                DB::table('certificate_templates')
                    ->where('id', $row->id)
                    ->update(['design' => json_encode($design), 'updated_at' => now()]);
            }
        }
    }
};
