<?php

namespace Tests\Unit\Services\Admin\IssuedCertificate;

use App\Enums\IssuedCertificateStatus;
use App\Models\DonorRecognition;
use App\Services\Admin\IssuedCertificate\IssuedCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuedCertificateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_counts_by_status_within_date_window(): void
    {
        DonorRecognition::query()->create([
            'recognition_number' => 'ICOBA-REC-2026-TEST0001',
            'donor_key' => 'donor-a',
            'awardee_name' => 'Donor A',
            'tier_uuid' => $this->createTier()->uuid,
            'cumulative_amount_ngn' => 1000000,
            'issued_at' => now()->subDays(5),
            'status' => IssuedCertificateStatus::AUTO_ISSUED,
        ]);

        DonorRecognition::query()->create([
            'recognition_number' => 'ICOBA-REC-2026-TEST0002',
            'donor_key' => 'donor-b',
            'awardee_name' => 'Donor B',
            'tier_uuid' => $this->createTier('Silver')->uuid,
            'cumulative_amount_ngn' => 2000000,
            'issued_at' => now()->subDays(10),
            'status' => IssuedCertificateStatus::REISSUED,
        ]);

        DonorRecognition::query()->create([
            'recognition_number' => 'ICOBA-REC-2026-TEST0003',
            'donor_key' => 'donor-c',
            'awardee_name' => 'Donor C',
            'tier_uuid' => $this->createTier('Gold')->uuid,
            'cumulative_amount_ngn' => 3000000,
            'issued_at' => now()->subDays(60),
            'status' => IssuedCertificateStatus::REVOKED,
        ]);

        $service = app(IssuedCertificateService::class);
        $stats = $service->stats(['period' => '30days']);

        $this->assertSame(2, $stats['total_count']);
        $this->assertSame(1, $stats['issued_count']);
        $this->assertSame(1, $stats['reissued_count']);
        $this->assertSame(0, $stats['revoked_count']);
    }

    public function test_paid_into_label_maps_known_gateways(): void
    {
        $this->assertSame('ICOBA Stripe', IssuedCertificateService::paidIntoLabel('stripe'));
        $this->assertSame('ICOBA Paystack', IssuedCertificateService::paidIntoLabel('paystack'));
        $this->assertSame('ICOBA FCMB', IssuedCertificateService::paidIntoLabel('fcmb'));
        $this->assertSame('ICOBA Access', IssuedCertificateService::paidIntoLabel('access'));
    }

    private function createTier(string $name = 'Bronze'): \App\Models\TierConfiguration
    {
        return \App\Models\TierConfiguration::query()->create([
            'name' => $name,
            'min_amount' => 100000,
            'is_active' => true,
        ]);
    }
}
