<?php

namespace Tests\Unit\Services\Admin\Report;

use App\Enums\CampaignStatus;
use App\Enums\PledgePaymentPlanType;
use App\Enums\PledgeStatus;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Mail\DailyDigestReportMail;
use App\Models\Admin;
use App\Models\Campaign;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\Report\DailyDigest\DailyDigestDocumentRenderer;
use App\Services\Admin\Report\DailyDigest\DailyDigestReportBuilder;
use App\Services\Admin\Report\DailyDigest\DailyDigestReportService;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Pledge\PledgeScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DailyDigestReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    private DailyDigestReportBuilder $builder;

    private Carbon $reportDate;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-11 07:00:00');
        $this->reportDate = Carbon::parse('2026-09-10 00:00:00');
        $this->builder = new DailyDigestReportBuilder(new PledgeScheduleService(new PledgeBalanceService));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_overdue_list_groups_by_donor_includes_partial_and_excludes_paused(): void
    {
        $campaign = $this->createCampaign('Library Fund');
        $donor = $this->createUser('Ada', 'Okoye', 'ada@example.com', '+2348012345678');

        // Pledge A: installment 1 overdue & partially paid, installment 2 due inside the upcoming window.
        $pledgeA = $this->createPledge($campaign, [
            'user_uuid' => $donor->uuid,
            'committed_amount' => 200000,
            'committed_amount_ngn' => 200000,
            'installment_count' => 2,
            'schedule' => [
                ['id' => 'a1', 'sequence' => 1, 'due_date' => '2026-09-01', 'amount' => 100000, 'amount_ngn' => 100000],
                ['id' => 'a2', 'sequence' => 2, 'due_date' => '2026-09-15', 'amount' => 100000, 'amount_ngn' => 100000],
            ],
        ]);
        $this->createTransaction($campaign, 40000, Carbon::parse('2026-09-05 10:00:00'), $pledgeA, $donor);

        // Pledge B: same donor (grouped), fully overdue, no payment. Anonymous publicly.
        $this->createPledge($campaign, [
            'user_uuid' => $donor->uuid,
            'is_anonymous' => true,
            'committed_amount' => 50000,
            'committed_amount_ngn' => 50000,
            'installment_count' => 1,
            'schedule' => [
                ['id' => 'b1', 'sequence' => 1, 'due_date' => '2026-06-01', 'amount' => 50000, 'amount_ngn' => 50000],
            ],
        ]);

        // Pledge C: guest donor, overdue.
        $this->createPledge($campaign, [
            'donor_name' => 'Guest Giver',
            'donor_email' => 'guest@example.com',
            'donor_phone' => '+2347000000000',
            'committed_amount' => 10000,
            'committed_amount_ngn' => 10000,
            'installment_count' => 1,
            'schedule' => [
                ['id' => 'c1', 'sequence' => 1, 'due_date' => '2026-09-10', 'amount' => 10000, 'amount_ngn' => 10000],
            ],
        ]);

        // Pledge D: paused, would otherwise be overdue.
        $this->createPledge($campaign, [
            'donor_name' => 'Paused Person',
            'donor_email' => 'paused@example.com',
            'committed_amount' => 30000,
            'committed_amount_ngn' => 30000,
            'installment_count' => 1,
            'metadata' => ['is_paused' => true, 'paused_at' => '2026-08-01T00:00:00Z', 'resume_date' => '2026-10-01'],
            'schedule' => [
                ['id' => 'd1', 'sequence' => 1, 'due_date' => '2026-08-15', 'amount' => 30000, 'amount_ngn' => 30000],
            ],
        ]);

        // Pledge E: not yet due — must not appear as overdue.
        $this->createPledge($campaign, [
            'donor_name' => 'Future Fan',
            'donor_email' => 'future@example.com',
            'committed_amount' => 5000,
            'committed_amount_ngn' => 5000,
            'installment_count' => 1,
            'schedule' => [
                ['id' => 'e1', 'sequence' => 1, 'due_date' => '2026-12-01', 'amount' => 5000, 'amount_ngn' => 5000],
            ],
        ]);

        $report = $this->builder->build($this->reportDate);

        $this->assertSame('2026-09-10', $report['report_date']);

        $overdue = $report['overdue'];
        $this->assertSame(2, $overdue['totals']['donors']);
        $this->assertSame(3, $overdue['totals']['pledges']);
        $this->assertSame(3, $overdue['totals']['installments']);
        // 60,000 (partial) + 50,000 + 10,000
        $this->assertSame('120000.00', $overdue['totals']['amount_ngn']);

        $ada = $overdue['donors'][0];
        $this->assertSame('Ada Okoye', $ada['donor']['name']);
        $this->assertSame('ada@example.com', $ada['donor']['email']);
        $this->assertSame('+2348012345678', $ada['donor']['phone']);
        $this->assertTrue($ada['donor']['is_registered']);
        $this->assertSame(2, $ada['totals']['pledges']);
        $this->assertSame('110000.00', $ada['totals']['overdue_ngn']);
        $this->assertSame('250000.00', $ada['totals']['committed_ngn']);
        $this->assertSame('40000.00', $ada['totals']['honored_ngn']);
        $this->assertSame('210000.00', $ada['totals']['pending_ngn']);

        $partial = collect($ada['pledges'])->firstWhere('pledge_uuid', $pledgeA->uuid);
        $this->assertNotNull($partial);
        $this->assertSame('60000.00', $partial['overdue_ngn']);
        $this->assertSame('40000.00', $partial['honored_ngn']);
        $this->assertSame(1, $partial['overdue_installments']);
        $this->assertSame('2026-09-01', $partial['earliest_due_date']);
        $this->assertSame(10, $partial['days_overdue']);
        $this->assertSame('2026-09-05', $partial['last_payment_at']);
        $this->assertSame('partial', $partial['items'][0]['status']);

        $anonymous = collect($ada['pledges'])->firstWhere('overdue_ngn', '50000.00');
        $this->assertTrue($anonymous['is_anonymous']);

        $guest = $overdue['donors'][1];
        $this->assertSame('Guest Giver', $guest['donor']['name']);
        $this->assertFalse($guest['donor']['is_registered']);
        $this->assertSame('+2347000000000', $guest['donor']['phone']);
        $this->assertSame(1, $guest['pledges'][0]['days_overdue']);

        // Aging: 10 days -> 8-30, 102 days -> over 90, 1 day -> 1-7
        $aging = collect($overdue['aging'])->keyBy('bucket');
        $this->assertSame(1, $aging['1-7 days']['count']);
        $this->assertSame(1, $aging['8-30 days']['count']);
        $this->assertSame(1, $aging['Over 90 days']['count']);

        // Paused pledge lands in the paused list, not overdue.
        $this->assertCount(1, $report['paused']);
        $this->assertSame('paused@example.com', $report['paused'][0]['donor']['email']);
        $this->assertSame('2026-10-01', $report['paused'][0]['resume_date']);

        // Upcoming window (7 days) catches installment a2 only.
        $this->assertCount(1, $report['upcoming']);
        $this->assertSame('2026-09-15', $report['upcoming'][0]['due_date']);

        // Active pledge totals (5 active pledges).
        $this->assertSame(5, $report['summary']['active_pledges']['count']);
        $this->assertSame('295000.00', $report['summary']['active_pledges']['committed_ngn']);
        $this->assertSame('40000.00', $report['summary']['active_pledges']['honored_ngn']);

        // Campaign schedule variance: due on/before 10 Sep = a1 100k + b1 50k + c1 10k + d1 30k = 190k; honored 40k.
        $campaignRow = collect($report['campaigns'])->firstWhere('uuid', $campaign->uuid);
        $this->assertSame('190000.00', $campaignRow['scheduled_to_date_ngn']);
        $this->assertSame('150000.00', $campaignRow['schedule_gap_ngn']);
        $this->assertSame('120000.00', $campaignRow['overdue_ngn']);
        $this->assertSame(3, $campaignRow['overdue_pledges']);
        $this->assertSame(1, $campaignRow['paused_pledges']);
    }

    public function test_donation_trend_and_variance_use_report_day_windows(): void
    {
        $campaign = $this->createCampaign('Science Block');

        $this->createTransaction($campaign, 30000, Carbon::parse('2026-09-10 09:00:00'));
        $this->createTransaction($campaign, 20000, Carbon::parse('2026-09-10 18:00:00'));
        $this->createTransaction($campaign, 25000, Carbon::parse('2026-09-09 12:00:00'));
        $this->createTransaction($campaign, 10000, Carbon::parse('2026-09-03 12:00:00')); // same weekday last week
        $this->createTransaction($campaign, 70000, Carbon::parse('2026-08-10 12:00:00')); // previous MTD
        $this->createTransaction($campaign, 99000, Carbon::parse('2026-09-11 01:00:00')); // after report date: ignored
        $this->createTransaction($campaign, 5000, Carbon::parse('2026-09-10 10:00:00'), null, null, TransactionStatus::PENDING); // not successful

        $report = $this->builder->build($this->reportDate);

        $this->assertSame('50000.00', $report['summary']['donations_yesterday']['amount']);
        $this->assertSame(2, $report['summary']['donations_yesterday']['count']);
        $this->assertSame('85000.00', $report['summary']['donations_mtd']['amount']);
        $this->assertSame('155000.00', $report['summary']['donations_ytd']['amount']);

        $daily = collect($report['trend']['daily']);
        $this->assertCount(14, $daily);
        $this->assertSame('2026-09-10', $daily->last()['date']);
        $this->assertSame('50000.00', $daily->last()['amount']);
        $this->assertSame('25000.00', $daily->firstWhere('date', '2026-09-09')['amount']);

        $v = $report['variance'];
        $this->assertSame('25000.00', $v['vs_previous_day']['difference']);
        $this->assertSame(100.0, $v['vs_previous_day']['percent']);
        $this->assertSame('increase', $v['vs_previous_day']['direction']);
        $this->assertSame('10000.00', $v['vs_same_weekday_last_week']['previous']);
        $this->assertSame(400.0, $v['vs_same_weekday_last_week']['percent']);
        // 7-day average of (25k + 10k + 0*5) / 7
        $this->assertSame('5000.00', $v['vs_7_day_average']['previous']);
        $this->assertSame('70000.00', $v['mtd_vs_previous_mtd']['previous']);
        $this->assertSame('15000.00', $v['mtd_vs_previous_mtd']['difference']);

        $monthly = collect($report['trend']['monthly']);
        $this->assertCount(6, $monthly);
        $this->assertSame('85000.00', $monthly->firstWhere('month', '2026-09')['amount']);
        $this->assertSame('70000.00', $monthly->firstWhere('month', '2026-08')['amount']);

        $campaignRow = collect($report['campaigns'])->firstWhere('uuid', $campaign->uuid);
        $this->assertSame('155000.00', $campaignRow['raised_ngn']);
        $this->assertSame('50000.00', $campaignRow['yesterday_ngn']);
        $this->assertSame(15.5, $campaignRow['progress_percent']);
    }

    public function test_renderer_produces_pdf_and_csv(): void
    {
        $campaign = $this->createCampaign('Hall Renovation');
        $this->createPledge($campaign, [
            'donor_name' => 'Late Larry',
            'donor_email' => 'larry@example.com',
            'donor_phone' => '+2348000000001',
            'committed_amount' => 10000,
            'committed_amount_ngn' => 10000,
            'installment_count' => 1,
            'schedule' => [
                ['id' => 'l1', 'sequence' => 1, 'due_date' => '2026-09-01', 'amount' => 10000, 'amount_ngn' => 10000],
            ],
        ]);

        $report = $this->builder->build($this->reportDate);
        $renderer = new DailyDigestDocumentRenderer;

        $pdf = $renderer->renderPdf($report);
        $this->assertStringStartsWith('%PDF', $pdf);

        $csv = $renderer->renderOverdueCsv($report);
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('Late Larry', $lines[1]);
        $this->assertStringContainsString('larry@example.com', $lines[1]);
        $this->assertStringContainsString('+2348000000001', $lines[1]);
        $this->assertStringContainsString('Hall Renovation', $lines[1]);
    }

    public function test_service_emails_active_admins_with_attachments_and_stores_copies(): void
    {
        Mail::fake();
        Storage::fake('local');
        config()->set('reports.daily_digest.storage_disk', 'local');
        config()->set('reports.daily_digest.extra_recipients', ['board@example.com']);

        $this->createAdmin('active@example.com');
        $this->createAdmin('inactive@example.com', ['is_active' => false]);
        $this->createAdmin('muted@example.com', ['email_notifications_enabled' => false]);
        $this->createAdmin('nologin@example.com', ['can_login' => false]);

        $this->createCampaign('Any');

        $service = app(DailyDigestReportService::class);
        $result = $service->generateAndSend($this->reportDate);

        $this->assertTrue($result['sent']);
        $this->assertEqualsCanonicalizing(['active@example.com', 'board@example.com'], $result['recipients']);
        $this->assertSame('reports/daily-digest/daily-digest-2026-09-10.pdf', $result['pdf_path']);
        Storage::disk('local')->assertExists('reports/daily-digest/daily-digest-2026-09-10.pdf');
        Storage::disk('local')->assertExists('reports/daily-digest/overdue-pledges-2026-09-10.csv');

        Mail::assertSent(DailyDigestReportMail::class, function (DailyDigestReportMail $mail): bool {
            return $mail->hasTo('active@example.com')
                && $mail->hasTo('board@example.com')
                && ! $mail->hasTo('inactive@example.com')
                && ! $mail->hasTo('muted@example.com')
                && ! $mail->hasTo('nologin@example.com')
                && count($mail->attachments()) === 2
                && $mail->report['report_date'] === '2026-09-10';
        });
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function createCampaign(string $name): Campaign
    {
        return Campaign::query()->create([
            'campaign_id' => 'CAMP-'.Str::upper(Str::random(8)),
            'name' => $name,
            'short_description' => 'Short description',
            'long_description' => 'Long description',
            'categories' => ['general'],
            'base_currency' => 'NGN',
            'available_donation_currencies' => ['NGN'],
            'target_amount' => 1000000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => CampaignStatus::ACTIVE->value,
        ]);
    }

    private function createUser(string $first, string $last, string $email, ?string $phone = null): User
    {
        return User::query()->create([
            'firstname' => $first,
            'lastname' => $last,
            'email' => $email,
            'phone_number' => $phone,
            'password' => 'secret-password',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPledge(Campaign $campaign, array $overrides = []): Pledge
    {
        return Pledge::query()->create(array_merge([
            'campaign_uuid' => $campaign->uuid,
            'committed_amount' => 100000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 100000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => PledgePaymentPlanType::MONTHLY->value,
            'installment_count' => 1,
            'status' => PledgeStatus::ACTIVE->value,
            'created_at' => '2026-05-01 00:00:00',
        ], $overrides));
    }

    private function createTransaction(
        Campaign $campaign,
        float $amount,
        Carbon $createdAt,
        ?Pledge $pledge = null,
        ?User $donor = null,
        TransactionStatus $status = TransactionStatus::SUCCESSFUL,
    ): Transaction {
        return Transaction::query()->create([
            'transaction_id' => 'TXN-'.Str::upper(Str::random(8)),
            'campaign_uuid' => $campaign->uuid,
            'pledge_uuid' => $pledge?->uuid,
            'user_uuid' => $donor?->uuid,
            'amount' => $amount,
            'currency' => 'NGN',
            'exchange_rate_to_naira' => 1,
            'amount_in_naira' => $amount,
            'status' => $status->value,
            'application_type' => $pledge !== null ? TransactionApplicationType::SCHEDULED_INSTALLMENT->value : TransactionApplicationType::INSTANT_DONATION->value,
            'gateway' => 'paystack',
            'paid_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'metadata' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAdmin(string $email, array $overrides = []): Admin
    {
        return Admin::query()->create(array_merge([
            'name' => 'Admin '.Str::random(4),
            'email' => $email,
            'password' => 'secret-password',
            'is_active' => true,
            'can_login' => true,
            'email_notifications_enabled' => true,
        ], $overrides));
    }
}
