<?php

namespace App\Services\Customer;

use App\Enums\PledgeScheduleItemStatus;
use App\Enums\PledgeStatus;
use App\Models\Pledge;
use App\Services\Pledge\PledgeScheduleService;
use Illuminate\Support\Collection;

class CustomerOverduePledgeService
{
    public function __construct(
        private readonly PledgeScheduleService $pledgeScheduleService,
    ) {}

    /**
     * @return list<array{pledge: Pledge, due_installment: array<string, mixed>}>
     */
    public function listForUser(string $userUuid): array
    {
        $pledges = Pledge::query()
            ->where('user_uuid', $userUuid)
            ->where('status', PledgeStatus::ACTIVE)
            ->with(['campaign:uuid,name,campaign_id'])
            ->orderByDesc('created_at')
            ->get();

        if ($pledges->isEmpty()) {
            return [];
        }

        $schedules = $this->pledgeScheduleService->buildForPledges($pledges);
        $results = [];

        foreach ($pledges as $pledge) {
            if ($this->pledgeScheduleService->isPledgePaused($pledge)) {
                continue;
            }

            $schedule = $schedules[$pledge->uuid] ?? null;
            if (! is_array($schedule)) {
                continue;
            }

            $dueInstallment = $this->selectNextOverdueInstallment($schedule['items'] ?? []);
            if ($dueInstallment === null) {
                continue;
            }

            $results[] = [
                'pledge' => $pledge,
                'due_installment' => $dueInstallment,
            ];
        }

        usort($results, function (array $a, array $b): int {
            $dateCompare = $this->compareDueDates(
                $a['due_installment']['due_date'] ?? null,
                $b['due_installment']['due_date'] ?? null,
            );
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ((int) ($a['due_installment']['sequence'] ?? 0)) <=> ((int) ($b['due_installment']['sequence'] ?? 0));
        });

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    public function selectNextOverdueInstallment(array $items): ?array
    {
        $overdue = collect($items)
            ->filter(function (array $item): bool {
                if ((float) ($item['remaining_amount'] ?? 0) <= 0.00001) {
                    return false;
                }

                return ($item['status'] ?? '') === PledgeScheduleItemStatus::PENDING->value;
            })
            ->sort(function (array $a, array $b): int {
                $dateCompare = $this->compareDueDates($a['due_date'] ?? null, $b['due_date'] ?? null);
                if ($dateCompare !== 0) {
                    return $dateCompare;
                }

                return ((int) ($a['sequence'] ?? 0)) <=> ((int) ($b['sequence'] ?? 0));
            })
            ->values();

        /** @var Collection<int, array<string, mixed>> $overdue */
        $first = $overdue->first();

        return is_array($first) ? $first : null;
    }

    private function compareDueDates(mixed $left, mixed $right): int
    {
        $leftKey = is_string($left) && $left !== '' ? $left : '9999-12-31';
        $rightKey = is_string($right) && $right !== '' ? $right : '9999-12-31';

        return $leftKey <=> $rightKey;
    }
}
