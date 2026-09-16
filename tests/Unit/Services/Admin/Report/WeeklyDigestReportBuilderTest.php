<?php

namespace Tests\Unit\Services\Admin\Report;

use App\Enums\CampaignStatus;
use App\Enums\PledgePaymentPlanType;
use App\Enums\PledgeStatus;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Mail\WeeklyDigestReportMail;
use App\Models\Admin;
use App\Models\Campaign;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\Report\WeeklyDigest\WeeklyDigestDocumentRenderer;
use App\Services\Admin\Report\WeeklyDigest\WeeklyDigestReportBuilder;
use App\Services\Admin\Report\WeeklyDigest\WeeklyDigestReportService;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Pledge\PledgeScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WeeklyDigestReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    private WeeklyDigestReportBuilder $builder;

    /** Sunday ending the report week (Mon 7 Sep – Sun 13 Sep 2026). */
    private Carbon $periodEnd;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday morning: the scheduler sends the digest for the week that just ended.
        Carbon::setTestNow('2026-09-14 07:00:00');
        $this->periodEnd = Carbon::parse('2026-09-13 00:00:00');
        $this->builder = new WeeklyDigestReportBuilder(new PledgeScheduleService(new PledgeBalanceService));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_period_resolves_to_a_full_monday_to_sunday_week(): void
    {
        // No date: the most recently completed week.
        $this->assertSame('2026-09-13', WeeklyDigestReportService::resolvePeriodEnd(null)->toDateString());

        // Any day inside a week resolves to that week's Sunday.
        $this->assertSame('2026-09-06', WeeklyDigestReportService::resolvePeriodEnd('2026-08-31')->toDateString()); // Monday
        $this->assertSame('2026-09-06', WeeklyDigestReportService::resolvePeriodEnd('2026-09-03')->toDateString()); // Thursday
        $this->assertSame('2026-09-06', WeeklyDigestReportService::resolvePeriodEnd('2026-09-06')->toDateString()); // Sunday

        // The builder normalises the same way and exposes the period.
        $report = $this->builder->build(Carbon::parse('2026-09-09'));
        $this->assertSame('2026-09-07', $report['period_start']);
        $this->assertSame('2026-09-13', $report['period_end']);
        $this->assertSame('2026-09-13', $report['report_date']);
        $this->assertSame(37, $report['iso_week']);
        $this->assertSame('Mon 7 Sep – Sun 13 Sep 2026', $report['period_label']);

        // Default period when no date is given.
        $default = $this->builder->build();
        $this->assertSame('2026-09-07', $default['period_start']);
        $this->assertSame('2026-09-13', $default['period_end']);
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

        // Pledge C: guest donor, fell due on the Thursday of the report week.
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

        // Pledge F: falls due the day after the report week — not overdue as of the Sunday, but upcoming.
        $this->createPledge($campaign, [
            'donor_name' => 'Monday Mo',
            'donor_email' => 'monday@example.com',
            'committed_amount' => 8000,
            'committed_amount_ngn' => 8000,
            'installment_count' => 1,
            'schedule' => [
                ['id' => 'f1', 'sequence' => 1, 'due_date' => '2026-09-14', 'amount' => 8000, 'amount_ngn' => 8000],
            ],
        ]);

        $report = $this->builder->build($this->periodEnd);

        $this->assertSame('2026-09-07', $report['period_start']);
        $this->assertSame('2026-09-13', $report['period_end']);

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
        // Days overdue are counted to the end of the report week (Sun 13 Sep), not to today.
        $this->assertSame(13, $partial['days_overdue']);
        $this->assertSame('2026-09-05', $partial['last_payment_at']);
        $this->assertSame('partial', $partial['items'][0]['status']);

        $anonymous = collect($ada['pledges'])->firstWhere('overdue_ngn', '50000.00');
        $this->assertTrue($anonymous['is_anonymous']);

        $guest = $overdue['donors'][1];
        $this->assertSame('Guest Giver', $guest['donor']['name']);
        $this->assertFalse($guest['donor']['is_registered']);
        $this->assertSame('+2347000000000', $guest['donor']['phone']);
        $this->assertSame(4, $guest['pledges'][0]['days_overdue']);

        // Aging: 13 days -> 8-30, 105 days -> over 90, 4 days -> 1-7
        $aging = collect($overdue['aging'])->keyBy('bucket');
        $this->assertSame(1, $aging['1-7 days']['count']);
        $this->assertSame(1, $aging['8-30 days']['count']);
        $this->assertSame(1, $aging['Over 90 days']['count']);

        // Paused pledge lands in the paused list, not overdue.
        $this->assertCount(1, $report['paused']);
        $this->assertSame('paused@example.com', $report['paused'][0]['donor']['email']);
        $this->assertSame('2026-10-01', $report['paused'][0]['resume_date']);

        // Upcoming window (7 days after the Sunday) catches f1 (Mon 14) and a2 (Tue 15), in due-date order.
        $this->assertCount(2, $report['upcoming']);
        $this->assertSame('2026-09-14', $report['upcoming'][0]['due_date']);
        $this->assertSame('2026-09-15', $report['upcoming'][1]['due_date']);

        // Active pledge totals (6 active pledges).
        $this->assertSame(6, $report['summary']['active_pledges']['count']);
        $this->assertSame('303000.00', $report['summary']['active_pledges']['committed_ngn']);
        $this->assertSame('40000.00', $report['summary']['active_pledges']['honored_ngn']);

        // Campaign schedule variance: due on/before 13 Sep = a1 100k + b1 50k + c1 10k + d1 30k = 190k; honored 40k.
        $campaignRow = collect($report['campaigns'])->firstWhere('uuid', $campaign->uuid);
        $this->assertSame('190000.00', $campaignRow['scheduled_to_date_ngn']);
        $this->assertSame('150000.00', $campaignRow['schedule_gap_ngn']);
        $this->assertSame('120000.00', $campaignRow['overdue_ngn']);
        $this->assertSame(3, $campaignRow['overdue_pledges']);
        $this->assertSame(1, $campaignRow['paused_pledges']);
    }

    public function test_donation_trend_and_variance_use_report_week_windows(): void
    {
        $campaign = $this->createCampaign('Science Block');

        // Report week: Mon 7 Sep – Sun 13 Sep 2026.
        $this->createTransaction($campaign, 30000, Carbon::parse('2026-09-10 09:00:00'));
        $this->createTransaction($campaign, 20000, Carbon::parse('2026-09-10 18:00:00'));
        $this->createTransaction($campaign, 25000, Carbon::parse('2026-09-09 12:00:00'));
        $this->createTransaction($campaign, 15000, Carbon::parse('2026-09-07 00:30:00')); // first minutes of the week: included
        $this->createTransaction($campaign, 10000, Carbon::parse('2026-09-03 12:00:00')); // previous week (31 Aug – 6 Sep)
        $this->createTransaction($campaign, 70000, Carbon::parse('2026-08-10 12:00:00')); // four weeks back; previous MTD
        $this->createTransaction($campaign, 40000, Carbon::parse('2025-09-10 12:00:00')); // same ISO week last year (8–14 Sep 2025)
        $this->createTransaction($campaign, 99000, Carbon::parse('2026-09-14 01:00:00')); // after the report week: ignored
        $this->createTransaction($campaign, 5000, Carbon::parse('2026-09-10 10:00:00'), null, null, TransactionStatus::PENDING); // not successful

        $report = $this->builder->build($this->periodEnd);

        $this->assertSame('90000.00', $report['summary']['donations_this_week']['amount']);
        $this->assertSame(4, $report['summary']['donations_this_week']['count']);
        $this->assertSame('100000.00', $report['summary']['donations_mtd']['amount']);
        $this->assertSame('170000.00', $report['summary']['donations_ytd']['amount']);
        $this->assertSame('210000.00', $report['summary']['donations_all_time']['amount']);

        $weekly = collect($report['trend']['weekly']);
        $this->assertCount(12, $weekly);
        $this->assertSame('2026-09-07', $weekly->last()['week_start']);
        $this->assertSame('2026-09-13', $weekly->last()['week_end']);
        $this->assertSame(37, $weekly->last()['iso_week']);
        $this->assertTrue($weekly->last()['is_current']);
        $this->assertSame('90000.00', $weekly->last()['amount']);
        $this->assertSame('10000.00', $weekly->firstWhere('week_start', '2026-08-31')['amount']);
        $this->assertSame('70000.00', $weekly->firstWhere('week_start', '2026-08-10')['amount']);
        $this->assertSame('2026-06-22', $weekly->first()['week_start']);
        $this->assertSame('7–13 Sep', $weekly->last()['label']);

        $daily = collect($report['trend']['daily']);
        $this->assertCount(7, $daily);
        $this->assertSame('2026-09-07', $daily->first()['date']);
        $this->assertSame('2026-09-13', $daily->last()['date']);
        $this->assertSame('50000.00', $daily->firstWhere('date', '2026-09-10')['amount']);
        $this->assertSame('2026-09-10', $report['trend']['peak_day']['date']);
        $this->assertSame('2026-09-07', $report['trend']['peak_week']['week_start']);

        $v = $report['variance'];
        $this->assertSame('10000.00', $v['vs_previous_week']['previous']);
        $this->assertSame('80000.00', $v['vs_previous_week']['difference']);
        $this->assertSame(800.0, $v['vs_previous_week']['percent']);
        $this->assertSame('increase', $v['vs_previous_week']['direction']);
        $this->assertSame('31 Aug – 6 Sep', $v['vs_previous_week']['comparison_label']);
        // 4-week average of (70k + 0 + 0 + 10k) / 4
        $this->assertSame('20000.00', $v['vs_4_week_average']['previous']);
        $this->assertSame('10 Aug – 6 Sep', $v['vs_4_week_average']['comparison_label']);
        $this->assertSame('40000.00', $v['vs_same_week_last_year']['previous']);
        $this->assertSame(125.0, $v['vs_same_week_last_year']['percent']);
        $this->assertSame('70000.00', $v['mtd_vs_previous_mtd']['previous']);
        $this->assertSame('30000.00', $v['mtd_vs_previous_mtd']['difference']);

        $monthly = collect($report['trend']['monthly']);
        $this->assertCount(6, $monthly);
        $this->assertSame('100000.00', $monthly->firstWhere('month', '2026-09')['amount']);
        $this->assertSame('70000.00', $monthly->firstWhere('month', '2026-08')['amount']);

        $campaignRow = collect($report['campaigns'])->firstWhere('uuid', $campaign->uuid);
        $this->assertSame('210000.00', $campaignRow['raised_ngn']);
        $this->assertSame('90000.00', $campaignRow['week_ngn']);
        $this->assertSame(4, $campaignRow['week_count']);
        $this->assertSame('100000.00', $campaignRow['mtd_ngn']);
        $this->assertSame(21.0, $campaignRow['progress_percent']);

        $method = collect($report['breakdowns']['payment_method'])->firstWhere('label', 'Paystack');
        $this->assertSame(4, $method['week_count']);
        $this->assertSame('90000.00', $method['week_amount']);
        $this->assertSame('100000.00', $method['mtd_amount']);
    }

    public function test_new_and_fulfilled_pledges_are_counted_within_the_week(): void
    {
        $campaign = $this->createCampaign('Sports Complex');

        $this->createPledge($campaign, ['donor_name' => 'In Week', 'donor_email' => 'in@example.com', 'committed_amount_ngn' => 20000, 'created_at' => '2026-09-08 09:00:00']);
        $this->createPledge($campaign, ['donor_name' => 'Sunday Night', 'donor_email' => 'sun@example.com', 'committed_amount_ngn' => 5000, 'created_at' => '2026-09-13 23:59:00']);
        $this->createPledge($campaign, ['donor_name' => 'Week Before', 'donor_email' => 'before@example.com', 'committed_amount_ngn' => 1000, 'created_at' => '2026-09-06 23:59:00']);
        $this->createPledge($campaign, ['donor_name' => 'Week After', 'donor_email' => 'after@example.com', 'committed_amount_ngn' => 1000, 'created_at' => '2026-09-14 00:01:00']);
        $this->createPledge($campaign, [
            'donor_name' => 'Done Dan',
            'donor_email' => 'dan@example.com',
            'status' => PledgeStatus::FULFILLED->value,
            'fulfilled_at' => '2026-09-11 10:00:00',
        ]);
        $this->createPledge($campaign, [
            'donor_name' => 'Done Earlier',
            'donor_email' => 'earlier@example.com',
            'status' => PledgeStatus::FULFILLED->value,
            'fulfilled_at' => '2026-09-01 10:00:00',
        ]);

        $report = $this->builder->build($this->periodEnd);

        $this->assertSame(2, $report['summary']['new_pledges_this_week']['count']);
        $this->assertSame('25000.00', $report['summary']['new_pledges_this_week']['committed_ngn']);
        $this->assertSame(['In Week', 'Sunday Night'], array_column(array_column($report['new_pledges'], 'donor'), 'name'));
        $this->assertSame('2026-09-08', $report['new_pledges'][0]['pledged_on']);

        $this->assertSame(1, $report['summary']['pledges_fulfilled_this_week']);
        $this->assertSame('Done Dan', $report['fulfilled_pledges'][0]['donor']['name']);
        $this->assertSame('2026-09-11', $report['fulfilled_pledges'][0]['fulfilled_at']);
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

        $report = $this->builder->build($this->periodEnd);
        $renderer = new WeeklyDigestDocumentRenderer;

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
        config()->set('reports.weekly_digest.storage_disk', 'local');
        config()->set('reports.weekly_digest.extra_recipients', ['board@example.com']);

        $this->createAdmin('active@example.com');
        $this->createAdmin('inactive@example.com', ['is_active' => false]);
        $this->createAdmin('muted@example.com', ['email_notifications_enabled' => false]);
        $this->createAdmin('nologin@example.com', ['can_login' => false]);

        $this->createCampaign('Any');

        $service = app(WeeklyDigestReportService::class);
        $result = $service->generateAndSend($this->periodEnd);

        $this->assertTrue($result['sent']);
        $this->assertSame('2026-09-07', $result['period_start']);
        $this->assertSame('2026-09-13', $result['period_end']);
        $this->assertEqualsCanonicalizing(['active@example.com', 'board@example.com'], $result['recipients']);
        $this->assertSame('reports/weekly-digest/weekly-digest-2026-09-07-to-2026-09-13.pdf', $result['pdf_path']);
        Storage::disk('local')->assertExists('reports/weekly-digest/weekly-digest-2026-09-07-to-2026-09-13.pdf');
        Storage::disk('local')->assertExists('reports/weekly-digest/overdue-pledges-2026-09-13.csv');

        Mail::assertSent(WeeklyDigestReportMail::class, function (WeeklyDigestReportMail $mail): bool {
            return $mail->hasTo('active@example.com')
                && $mail->hasTo('board@example.com')
                && ! $mail->hasTo('inactive@example.com')
                && ! $mail->hasTo('muted@example.com')
                && ! $mail->hasTo('nologin@example.com')
                && count($mail->attachments()) === 2
                && $mail->report['period_end'] === '2026-09-13'
                && str_contains($mail->envelope()->subject, 'Weekly donations & pledges digest');
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
