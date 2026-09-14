<?php

namespace Tests\Unit\Services\Admin\Pledge;

use App\Enums\PledgeStatus;
use App\Enums\TransactionStatus;
use App\Http\Resources\PledgeListResource;
use App\Models\Campaign;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Services\Admin\Pledge\PledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PledgeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_include_total_values_for_pledge_value_fulfilled_and_active(): void
    {
        $campaign = Campaign::query()->create([
            'uuid' => 'campaign-pledge-stats',
            'campaign_id' => 'CMP-001',
            'name' => 'Campaign 1',
            'short_description' => 'desc',
            'long_description' => 'desc',
            'categories' => ['general'],
            'base_currency' => 'NGN',
            'available_donation_currencies' => ['NGN'],
            'target_amount' => 10000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'allow_anonymous_donation' => true,
            'allow_public_donation' => true,
            'applies_to_all_graduation_sets' => true,
            'status' => 'active',
        ]);

        $activePledge = Pledge::query()->create([
            'uuid' => 'pledge-active-1',
            'campaign_uuid' => $campaign->uuid,
            'user_uuid' => null,
            'donor_name' => 'Jane Doe',
            'donor_email' => 'jane@example.com',
            'donor_phone' => '08030000001',
            'is_anonymous' => false,
            'committed_amount' => 1000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 1000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => 'monthly',
            'installment_count' => 1,
            'status' => PledgeStatus::ACTIVE,
        ]);

        $fulfilledPledge = Pledge::query()->create([
            'uuid' => 'pledge-fulfilled-1',
            'campaign_uuid' => $campaign->uuid,
            'user_uuid' => null,
            'donor_name' => 'John Doe',
            'donor_email' => 'john@example.com',
            'donor_phone' => '08030000002',
            'is_anonymous' => false,
            'committed_amount' => 2000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 2000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => 'monthly',
            'installment_count' => 1,
            'status' => PledgeStatus::FULFILLED,
        ]);

        Transaction::query()->create([
            'transaction_id' => 'TRN-ACTIVE-1',
            'uuid' => 'txn-active-1',
            'campaign_uuid' => $campaign->uuid,
            'pledge_uuid' => $activePledge->uuid,
            'user_uuid' => null,
            'donor_name' => 'Jane Doe',
            'donor_email' => 'jane@example.com',
            'donor_phone' => '08030000001',
            'amount' => 400,
            'currency' => 'NGN',
            'amount_in_naira' => 400,
            'status' => TransactionStatus::SUCCESSFUL,
            'gateway' => 'paystack',
        ]);

        Transaction::query()->create([
            'transaction_id' => 'TRN-FULFILLED-1',
            'uuid' => 'txn-fulfilled-1',
            'campaign_uuid' => $campaign->uuid,
            'pledge_uuid' => $fulfilledPledge->uuid,
            'user_uuid' => null,
            'donor_name' => 'John Doe',
            'donor_email' => 'john@example.com',
            'donor_phone' => '08030000002',
            'amount' => 2000,
            'currency' => 'NGN',
            'amount_in_naira' => 2000,
            'status' => TransactionStatus::SUCCESSFUL,
            'gateway' => 'paystack',
        ]);

        $service = app(PledgeService::class);
        $stats = $service->stats([]);

        $this->assertSame('3000.00', $stats['total_pledge_value']);
        $this->assertSame('2400.00', $stats['total_fulfilled']);
        $this->assertSame('600.00', $stats['total_active']);
    }

    public function test_pledge_list_resource_includes_amount_aliases(): void
    {
        $pledge = new Pledge([
            'uuid' => 'pledge-list-resource-1',
            'campaign_uuid' => 'campaign-uuid-1',
            'user_uuid' => null,
            'donor_name' => 'Jane Doe',
            'donor_email' => 'jane@example.com',
            'donor_phone' => '08030000001',
            'is_anonymous' => false,
            'committed_amount' => 1500,
            'committed_amount_ngn' => 1500,
            'currency' => 'NGN',
            'payment_plan_type' => 'monthly',
            'installment_count' => 2,
            'status' => PledgeStatus::ACTIVE,
        ]);

        $pledge->setAttribute('fulfilled_amount', '450.00');
        $pledge->setAttribute('remaining_amount', '1050.00');

        $payload = PledgeListResource::make($pledge)->resolve();

        $this->assertSame('1500.00', $payload['amount_pledged']);
        $this->assertSame('450.00', $payload['amount_fulfilled']);
        $this->assertSame('1050.00', $payload['amount_pending']);
    }
}
