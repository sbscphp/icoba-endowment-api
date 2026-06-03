<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use App\Services\Admin\Campaign\CampaignService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class CompleteEligibleCampaignsCommand extends Command
{
    protected $signature = 'campaigns:auto-complete {--dry-run : List eligible campaigns without transitioning}';

    protected $description = 'Complete active or paused campaigns when the end date has passed or the NGN target is reached.';

    public function handle(CampaignService $campaignService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        /** @var Carbon $today */
        $today = today((string) config('app.timezone'));

        $candidates = Campaign::query()
            ->whereIn('status', [CampaignStatus::ACTIVE, CampaignStatus::PAUSED])
            ->withSum(['transactions as successful_ngn' => function ($q): void {
                $q->where('status', TransactionStatus::SUCCESSFUL);
            }], 'amount_in_naira')
            ->get();

        $eligible = [];
        foreach ($candidates as $campaign) {
            $reasonCode = null;
            if ($campaign->end_date !== null) {
                $end = Carbon::parse($campaign->end_date)->copy()->startOfDay();
                if ($end->lt($today)) {
                    $reasonCode = 'end_date_reached';
                }
            }
            if ($reasonCode === null) {
                $raised = (float) ($campaign->successful_ngn ?? 0);
                $target = (float) $campaign->target_amount;
                if ($target > 0 && $raised >= $target) {
                    $reasonCode = 'target_reached';
                }
            }

            if ($reasonCode !== null) {
                $eligible[] = ['campaign' => $campaign, 'reason' => $reasonCode];
            }
        }

        $done = 0;
        $request = Request::create('https://console/campaigns:auto-complete', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        foreach ($eligible as $item) {
            if ($dryRun) {
                $this->line($item['campaign']->uuid.' '.$item['reason']);
                $done++;

                continue;
            }
            try {
                $campaignService->complete(
                    $item['campaign']->uuid,
                    null,
                    'Auto-completed: '.$item['reason'],
                    $request
                );
                $done++;
            } catch (\Throwable $th) {
                $this->warn($th->getMessage());
            }
        }

        $this->info('Completed '.$done.'/'.count($eligible).' campaigns (dry-run='.($dryRun ? 'true' : 'false').').');

        return self::SUCCESS;
    }
}
