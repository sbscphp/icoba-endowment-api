<?php

namespace App\Jobs;

use App\Enums\BulkEmailStatus;
use App\Mail\CampaignBulkEmailMail;
use App\Models\CampaignEmail;
use App\Services\Theme\ThemeResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCampaignBulkEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $campaignEmailUuid,
        public readonly string $toEmail,
        public readonly ?string $toName = null,
    ) {}

    public function handle(): void
    {
        $row = CampaignEmail::query()
            ->where('uuid', $this->campaignEmailUuid)
            ->with('campaign')
            ->first();

        if ($row === null || $row->campaign === null) {
            return;
        }

        try {
            $theme = app(ThemeResolver::class)->resolveForMail();
            Mail::to($this->toEmail)->send(new CampaignBulkEmailMail($row, $row->campaign, $this->toEmail, $theme, $this->toName));
            $this->recordDeliveryResult($this->campaignEmailUuid, true);
        } catch (\Throwable $e) {
            Log::warning('Bulk email send failed: '.$e->getMessage(), [
                'campaign_email_uuid' => $this->campaignEmailUuid,
                'to' => $this->toEmail,
            ]);
            $this->recordDeliveryResult($this->campaignEmailUuid, false);
        }
    }

    private function recordDeliveryResult(string $uuid, bool $success): void
    {
        DB::transaction(function () use ($uuid, $success): void {
            /** @var CampaignEmail|null $row */
            $row = CampaignEmail::query()->lockForUpdate()->where('uuid', $uuid)->first();
            if ($row === null) {
                return;
            }

            if ($success) {
                $row->increment('successful_count');
            } else {
                $row->increment('failed_count');
            }

            $row->refresh();

            $total = (int) ($row->total_recipients ?? 0);
            if ($total <= 0) {
                return;
            }

            $processed = (int) $row->successful_count + (int) $row->failed_count;
            if ($processed < $total) {
                return;
            }

            if ($row->failed_count === 0) {
                $row->status = BulkEmailStatus::SENT;
            } elseif ($row->successful_count === 0) {
                $row->status = BulkEmailStatus::FAILED;
            } else {
                $row->status = BulkEmailStatus::PARTIALLY_SENT;
            }

            $row->sent_at = $row->sent_at ?? now();
            $row->save();
        });
    }
}
