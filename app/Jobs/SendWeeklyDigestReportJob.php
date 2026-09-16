<?php

namespace App\Jobs;

use App\Services\Admin\Report\WeeklyDigest\WeeklyDigestReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWeeklyDigestReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * @param  string  $periodEnd  Any date (Y-m-d) inside the week to report on; resolved to that week's Sunday.
     * @param  list<string>|null  $recipients  Override the resolved admin recipients (used by the command's --to option).
     */
    public function __construct(
        public readonly string $periodEnd,
        public readonly ?array $recipients = null,
    ) {}

    public function uniqueId(): string
    {
        return 'weekly-digest-report:'.$this->periodEnd.':'.md5(implode(',', $this->recipients ?? ['admins']));
    }

    public function handle(WeeklyDigestReportService $service): void
    {
        $result = $service->generateAndSend(
            WeeklyDigestReportService::resolvePeriodEnd($this->periodEnd),
            $this->recipients,
        );

        if (! $result['sent']) {
            Log::warning('Weekly digest report job finished without sending.', [
                'period_start' => $result['period_start'],
                'period_end' => $result['period_end'],
            ]);
        }
    }
}
