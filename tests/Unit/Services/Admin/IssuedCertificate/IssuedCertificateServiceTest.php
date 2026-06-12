<?php

namespace Tests\Unit\Services\Admin\IssuedCertificate;

use App\Enums\IssuedCertificateStatus;
use App\Jobs\SendDonorRecognitionRevokedEmailJob;
use App\Models\DonorRecognition;
use App\Services\Admin\IssuedCertificate\IssuedCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_revoke_marks_certificate_revoked_and_clears_download_token(): void
    {
        Queue::fake();

        $tier = $this->createTier();
        $recognition = DonorRecognition::query()->create([
            'recognition_number' => 'ICOBA-REC-2026-TEST0004',
            'donor_key' => 'donor-d',
            'donor_email' => 'donor@example.com',
            'awardee_name' => 'Donor D',
            'tier_uuid' => $tier->uuid,
            'cumulative_amount_ngn' => 1000000,
            'issued_at' => now(),
            'status' => IssuedCertificateStatus::AUTO_ISSUED,
            'download_token' => 'existing-token',
        ]);

        $service = app(IssuedCertificateService::class);
        $revoked = $service->revoke($recognition->uuid);

        $this->assertSame(IssuedCertificateStatus::REVOKED, $revoked->status);
        $this->assertNull($revoked->download_token);
        Queue::assertPushed(
            SendDonorRecognitionRevokedEmailJob::class,
            fn (SendDonorRecognitionRevokedEmailJob $job): bool => $job->recognitionUuid === $recognition->uuid,
        );
    }

    public function test_reissue_updates_reference_and_status(): void
    {
        $tier = $this->createTier();
        $template = \App\Models\CertificateTemplate::query()->create([
            'name' => 'Bronze Template',
            'tier_uuid' => $tier->uuid,
            'design' => ['lines' => []],
            'is_active' => true,
        ]);

        $recognition = DonorRecognition::query()->create([
            'recognition_number' => 'ICOBA-REC-2026-TEST0005',
            'donor_key' => 'donor-e',
            'awardee_name' => 'Donor E',
            'tier_uuid' => $tier->uuid,
            'certificate_template_uuid' => $template->uuid,
            'cumulative_amount_ngn' => 1000000,
            'issued_at' => now()->subDay(),
            'status' => IssuedCertificateStatus::AUTO_ISSUED,
            'download_token' => 'old-token',
            'snapshot' => ['tier_name' => 'Bronze'],
        ]);

        $service = app(IssuedCertificateService::class);
        $reissued = $service->reissue($recognition->uuid, ['send_email' => false]);

        $this->assertSame(IssuedCertificateStatus::REISSUED, $reissued->status);
        $this->assertNotSame('ICOBA-REC-2026-TEST0005', $reissued->recognition_number);
        $this->assertNotSame('old-token', $reissued->download_token);
        $this->assertSame($template->uuid, $reissued->certificate_template_uuid);
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
