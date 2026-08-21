<?php

namespace Tests\Unit\Services\Donation;

use App\Services\Donation\BankTransferReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankTransferReferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_reference_uses_ref_prefix(): void
    {
        $service = new BankTransferReferenceService;

        $reference = $service->generateUniqueReference(10);

        $this->assertStringStartsWith('ICB-ENMT-REF-', $reference);
        $this->assertGreaterThan(strlen('ICB-ENMT-REF-'), strlen($reference));
    }

    public function test_extract_pulls_reference_from_narration(): void
    {
        $service = new BankTransferReferenceService;

        $reference = $service->extractFromNarration('Transfer from John Doe ICB-ENMT-REF-82Re93GHA for endowment');

        $this->assertSame('ICB-ENMT-REF-82Re93GHA', $reference);
    }

    public function test_extract_returns_null_when_no_reference_present(): void
    {
        $service = new BankTransferReferenceService;

        $this->assertNull($service->extractFromNarration('Random unrelated narration without a code'));
        $this->assertNull($service->extractFromNarration(null));
        $this->assertNull($service->extractFromNarration(''));
    }
}
