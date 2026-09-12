<?php

namespace App\Jobs;

use App\Services\Admin\Report\DailyDigest\DailyDigestReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendDailyDigestReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * @param  list<string>|null  $recipients  Override the resolved admin recipients (used by the command's --to option).
     */
    public function __construct(
        public readonly string $reportDate,
        public readonly ?array $recipients = null,
    ) {}

    public function uniqueId(): string
    {
        return 'daily-digest-report:'.$this->reportDate.':'.md5(implode(',', $this->recipients ?? ['admins']));
    }

    public function handle(DailyDigestReportService $service): void
    {
        $result = $service->generateAndSend(
            DailyDigestReportService::resolveReportDate($this->reportDate),
            $this->recipients,
        );

        if (! $result['sent']) {
            Log::warning('Daily digest report job finished without sending.', [
                'report_date' => $this->reportDate,
            ]);
        }
    }
}
