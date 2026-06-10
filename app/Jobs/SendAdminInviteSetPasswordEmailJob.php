<?php

namespace App\Jobs;

use App\Models\Admin;
use App\Notifications\Auth\AdminInviteSetPasswordMail;
use App\Services\Auth\PasswordResetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAdminInviteSetPasswordEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $adminUuid,
        public readonly ?string $frontendUrl = null,
    ) {}

    public function uniqueId(): string
    {
        return 'admin-invite-set-password:'.$this->adminUuid;
    }

    public function handle(PasswordResetService $passwordResetService): void
    {
        $admin = Admin::query()
            ->where('uuid', $this->adminUuid)
            ->first();

        if ($admin === null) {
            return;
        }

        try {
            $resetToken = $passwordResetService->issueResetTokenFor($admin);

            $admin->notify(new AdminInviteSetPasswordMail(
                token: $resetToken,
                resetUrl: $passwordResetService->adminSetPasswordUrl($resetToken, $this->frontendUrl),
            ));
        } catch (\Throwable $e) {
            Log::warning('Admin invite set-password email failed: '.$e->getMessage(), [
                'admin_uuid' => $this->adminUuid,
                'to' => $admin->email,
            ]);
        }
    }
}
