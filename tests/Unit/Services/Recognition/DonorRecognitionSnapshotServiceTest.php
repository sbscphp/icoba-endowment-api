<?php

namespace Tests\Unit\Services\Recognition;

use App\Enums\IssuedCertificateStatus;
use App\Models\CertificateTemplate;
use App\Models\DonorRecognition;
use App\Models\TierConfiguration;
use App\Services\Recognition\DonorRecognitionSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonorRecognitionSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_regenerate_updates_snapshot_design_from_template(): void
    {
        $tier = $this->createTier();
        $template = CertificateTemplate::query()->create([
            'name' => 'Bronze Certificate',
            'tier_uuid' => $tier->uuid,
            'design' => ['image_url' => 'https://example.com/new.png', 'lines' => []],
            'is_active' => true,
        ]);

        $recognition = DonorRecognition::query()->create([
            'recognition_number' => 'ICOBA-REC-2026-SNAP0001',
            'donor_key' => 'donor-snap',
            'awardee_name' => 'Snap Donor',
            'tier_uuid' => $tier->uuid,
            'certificate_template_uuid' => $template->uuid,
            'cumulative_amount_ngn' => 1000000,
            'initial_amount' => 500000,
            'initial_currency' => 'NGN',
            'issued_at' => now(),
            'status' => IssuedCertificateStatus::AUTO_ISSUED,
            'snapshot' => [
                'tier_name' => $tier->name,
                'template_name' => $template->name,
                'design' => ['image_url' => 'https://example.com/old.png', 'lines' => []],
                'initial_amount' => '500000.00',
                'initial_currency' => 'NGN',
            ],
        ]);

        $service = app(DonorRecognitionSnapshotService::class);
        $stats = $service->regenerate([
            'template' => $template->uuid,
        ]);

        $recognition->refresh();

        $this->assertSame(1, $stats['updated']);
        $this->assertSame('https://example.com/new.png', $recognition->snapshot['design']['image_url']);
        $this->assertSame('500000.00', $recognition->snapshot['initial_amount']);
        $this->assertSame('NGN', $recognition->snapshot['initial_currency']);
    }

    public function test_regenerate_skips_revoked_recognitions(): void
    {
        $tier = $this->createTier();
        $template = CertificateTemplate::query()->create([
            'name' => 'Bronze Certificate',
            'tier_uuid' => $tier->uuid,
            'design' => ['lines' => []],
            'is_active' => true,
        ]);

        DonorRecognition::query()->create([
            'recognition_number' => 'ICOBA-REC-2026-SNAP0002',
            'donor_key' => 'donor-revoked',
            'awardee_name' => 'Revoked Donor',
            'tier_uuid' => $tier->uuid,
            'certificate_template_uuid' => $template->uuid,
            'cumulative_amount_ngn' => 1000000,
            'issued_at' => now(),
            'status' => IssuedCertificateStatus::REVOKED,
            'snapshot' => ['design' => ['lines' => []]],
        ]);

        $service = app(DonorRecognitionSnapshotService::class);
        $stats = $service->regenerate([
            'template' => $template->uuid,
            'dry_run' => true,
        ]);

        $this->assertSame(0, $stats['scanned']);
    }

    private function createTier(string $name = 'Bronze Contributor'): TierConfiguration
    {
        return TierConfiguration::query()->create([
            'name' => $name,
            'min_amount' => 1000000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }
}
