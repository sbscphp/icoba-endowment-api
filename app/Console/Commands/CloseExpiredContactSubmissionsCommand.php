<?php

namespace App\Console\Commands;

use App\Services\Contact\ContactSubmissionService;
use Illuminate\Console\Command;

class CloseExpiredContactSubmissionsCommand extends Command
{
    protected $signature = 'contact-submissions:auto-close {--dry-run : List eligible submissions without closing them}';

    protected $description = 'Close contact submissions that have been resolved for at least 7 days.';

    public function handle(ContactSubmissionService $contactSubmissionService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $closed = $contactSubmissionService->closeExpiredResolvedSubmissions($dryRun);

        $this->info(($dryRun ? 'Eligible' : 'Closed').' '.$closed.' contact submission(s).');

        return self::SUCCESS;
    }
}
