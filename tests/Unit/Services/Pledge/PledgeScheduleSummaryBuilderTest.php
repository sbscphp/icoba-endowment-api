<?php

namespace Tests\Unit\Services\Pledge;

use App\Enums\PledgePaymentPlanType;
use App\Enums\PledgeStatus;
use App\Models\Pledge;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Pledge\PledgeScheduleService;
use App\Services\Pledge\PledgeScheduleSummaryBuilder;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PledgeScheduleSummaryBuilderTest extends TestCase
{
    private PledgeScheduleSummaryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new PledgeScheduleSummaryBuilder(new PledgeScheduleService(new PledgeBalanceService));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_overdue_installments_are_counted_with_partial_payments_and_next_installment_is_first_unpaid(): void
    {
        $asOf = Carbon::parse('2026-09-12 09:00:00');

        $summary = $this->builder->build($this->activePledge(), $this->scheduleView([
            $this->item('a', 1, '2026-07-01', paid: 100, pledged: 100),
            $this->item('b', 2, '2026-08-01', paid: 40, pledged: 100),
            $this->item('c', 3, '2026-09-12', paid: 0, pledged: 100),
            $this->item('d', 4, '2026-10-01', paid: 0, pledged: 100),
        ]), '2026-07-01T10:00:00+00:00', $asOf);

        $this->assertSame(4, $summary['installments_total']);
        $this->assertSame(1, $summary['installments_paid']);
        $this->assertSame(3, $summary['installments_outstanding']);
        $this->assertTrue($summary['is_overdue']);
        $this->assertSame(2, $summary['overdue_installments']);
        $this->assertSame('160.00', $summary['overdue_amount']);
        $this->assertSame('160.00', $summary['overdue_amount_ngn']);
        $this->assertSame('2026-08-01', $summary['earliest_overdue_due_date']);
        $this->assertSame(43, $summary['days_overdue']);
        $this->assertSame('b', $summary['next_installment']['id']);
        $this->assertSame('60.00', $summary['next_installment']['remaining_amount']);
        $this->assertSame('2026-07-01T10:00:00+00:00', $summary['last_payment_at']);
    }

    public function test_paused_or_inactive_pledges_are_never_overdue(): void
    {
        $asOf = Carbon::parse('2026-09-12 09:00:00');
        $view = $this->scheduleView([
            $this->item('a', 1, '2026-08-01', paid: 0, pledged: 100),
        ]);

        $paused = $this->activePledge(['metadata' => ['is_paused' => true]]);
        $summary = $this->builder->build($paused, $view, null, $asOf);
        $this->assertFalse($summary['is_overdue']);
        $this->assertSame(0, $summary['overdue_installments']);
        $this->assertSame('a', $summary['next_installment']['id']);

        $cancelled = $this->activePledge(['status' => PledgeStatus::CANCELLED]);
        $summary = $this->builder->build($cancelled, $view, null, $asOf);
        $this->assertFalse($summary['is_overdue']);
        $this->assertSame(0, $summary['days_overdue']);
    }

    public function test_reminder_counts_come_from_metadata(): void
    {
        $pledge = $this->activePledge(['metadata' => [
            'payment_reminders_sent' => ['a:2026-08-01', 'b:2026-09-01'],
            'manual_reminders' => [
                ['sent_at' => '2026-09-01T08:00:00+00:00', 'admin_uuid' => 'admin-1'],
                ['sent_at' => '2026-09-10T08:00:00+00:00', 'admin_uuid' => 'admin-2'],
            ],
        ]]);

        $summary = $this->builder->build($pledge, $this->scheduleView([]), null, Carbon::parse('2026-09-12'));

        $this->assertSame(2, $summary['automatic_reminders_sent']);
        $this->assertSame(2, $summary['manual_reminders_sent']);
        $this->assertSame('2026-09-10T08:00:00+00:00', $summary['last_reminder_sent_at']);
        $this->assertNull($summary['next_installment']);
        $this->assertSame(0, $summary['installments_total']);
    }

    public function test_summary_exposes_number_of_transactions(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('transaction_id', 48)->unique();
            $table->uuid('campaign_uuid');
            $table->uuid('user_uuid')->nullable();
            $table->string('donor_name')->nullable();
            $table->string('donor_email')->nullable();
            $table->string('donor_phone', 32)->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 8);
            $table->decimal('amount_in_naira', 18, 2)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('gateway')->nullable();
            $table->uuid('pledge_uuid')->nullable();
            $table->timestamps();
        });

        $pledge = $this->activePledge(['uuid' => 'pledge-summary-transaction-count']);

        $pledge->transactions()->create([
            'transaction_id' => 'TRN-001',
            'campaign_uuid' => 'campaign-uuid',
            'user_uuid' => null,
            'donor_name' => 'Jane Doe',
            'donor_email' => 'jane@example.com',
            'donor_phone' => '08030000001',
            'amount' => 100,
            'currency' => 'NGN',
            'amount_in_naira' => 100,
            'status' => 'successful',
            'gateway' => 'paystack',
            'pledge_uuid' => $pledge->uuid,
        ]);

        $pledge->transactions()->create([
            'transaction_id' => 'TRN-002',
            'campaign_uuid' => 'campaign-uuid',
            'user_uuid' => null,
            'donor_name' => 'Jane Doe',
            'donor_email' => 'jane@example.com',
            'donor_phone' => '08030000001',
            'amount' => 150,
            'currency' => 'NGN',
            'amount_in_naira' => 150,
            'status' => 'pending',
            'gateway' => 'paystack',
            'pledge_uuid' => $pledge->uuid,
        ]);

        $summary = $this->builder->build($pledge, $this->scheduleView([]), null, Carbon::parse('2026-09-12'));
        $summary['number_of_transactions'] = 2;

        $this->assertSame(2, $summary['number_of_transactions']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function activePledge(array $overrides = []): Pledge
    {
        return new Pledge(array_merge([
            'uuid' => 'pledge-summary-test',
            'committed_amount' => 400,
            'currency' => 'NGN',
            'committed_amount_ngn' => 400,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => PledgePaymentPlanType::MONTHLY,
            'installment_count' => 4,
            'status' => PledgeStatus::ACTIVE,
        ], $overrides));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function scheduleView(array $items): array
    {
        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $id, int $sequence, string $dueDate, float $paid, float $pledged): array
    {
        $remaining = max(0, $pledged - $paid);
        $status = $remaining <= 0.00001 ? 'paid' : ($paid > 0 ? 'partial' : 'pending');

        return [
            'id' => $id,
            'sequence' => $sequence,
            'due_date' => $dueDate,
            'pledged_amount' => number_format($pledged, 2, '.', ''),
            'pledged_amount_ngn' => number_format($pledged, 2, '.', ''),
            'paid_amount' => number_format($paid, 2, '.', ''),
            'paid_amount_ngn' => number_format($paid, 2, '.', ''),
            'remaining_amount' => number_format($remaining, 2, '.', ''),
            'remaining_amount_ngn' => number_format($remaining, 2, '.', ''),
            'currency' => 'NGN',
            'status' => $status,
        ];
    }
}
