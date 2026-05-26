<?php

namespace Tests\Unit\Services\Customer;

use App\Enums\PledgeScheduleItemStatus;
use App\Services\Customer\CustomerOverduePledgeService;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Pledge\PledgeScheduleService;
use Tests\TestCase;

final class CustomerOverduePledgeServiceTest extends TestCase
{
    private CustomerOverduePledgeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CustomerOverduePledgeService(
            new PledgeScheduleService(new PledgeBalanceService)
        );
    }

    public function test_select_next_overdue_installment_returns_earliest_overdue_item(): void
    {
        $items = [
            [
                'id' => 'item-2',
                'sequence' => 2,
                'due_date' => '2026-06-01',
                'remaining_amount' => '90.00',
                'status' => PledgeScheduleItemStatus::OVERDUE->value,
            ],
            [
                'id' => 'item-1',
                'sequence' => 1,
                'due_date' => '2026-05-01',
                'remaining_amount' => '90.00',
                'status' => PledgeScheduleItemStatus::OVERDUE->value,
            ],
        ];

        $selected = $this->service->selectNextOverdueInstallment($items);

        $this->assertNotNull($selected);
        $this->assertSame('item-1', $selected['id']);
        $this->assertSame(PledgeScheduleItemStatus::OVERDUE->value, $selected['status']);
    }

    public function test_select_next_overdue_installment_ignores_pending_and_partial_items(): void
    {
        $items = [
            [
                'id' => 'item-1',
                'sequence' => 1,
                'due_date' => '2026-05-01',
                'remaining_amount' => '90.00',
                'status' => PledgeScheduleItemStatus::PENDING->value,
            ],
            [
                'id' => 'item-2',
                'sequence' => 2,
                'due_date' => '2026-04-01',
                'remaining_amount' => '40.00',
                'status' => PledgeScheduleItemStatus::PARTIAL->value,
            ],
        ];

        $this->assertNull($this->service->selectNextOverdueInstallment($items));
    }

    public function test_select_next_overdue_installment_ignores_paid_and_zero_balance_items(): void
    {
        $items = [
            [
                'id' => 'item-1',
                'sequence' => 1,
                'due_date' => '2026-05-01',
                'remaining_amount' => '0.00',
                'status' => PledgeScheduleItemStatus::PAID->value,
            ],
            [
                'id' => 'item-2',
                'sequence' => 2,
                'due_date' => '2026-06-01',
                'remaining_amount' => '0.00',
                'status' => PledgeScheduleItemStatus::OVERDUE->value,
            ],
        ];

        $this->assertNull($this->service->selectNextOverdueInstallment($items));
    }
}
