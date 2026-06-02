<?php

namespace App\Jobs;

use App\Enums\ModuleEnums;
use App\Models\User;
use App\Notifications\GenericDatabaseNotification;
use App\Services\Donation\GuestDonorHistoryLinkerService;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class LinkGuestDonorHistoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $userUuid,
    ) {}

    public function uniqueId(): string
    {
        return 'link-guest-history:'.$this->userUuid;
    }

    public function handle(
        GuestDonorHistoryLinkerService $linker,
        NotificationDispatchService $notificationDispatch,
    ): void {
        $user = User::query()->where('uuid', $this->userUuid)->first();
        if ($user === null) {
            return;
        }

        try {
            $result = $linker->linkForUser($user);

            if ($result['transactions'] > 0 || $result['pledges'] > 0 || $result['pledge_transactions'] > 0 || $result['recognitions'] > 0) {
                Log::info('Linked guest donor history to new user.', [
                    'user_uuid' => $this->userUuid,
                    'email' => $user->email,
                    ...$result,
                ]);

                $frontendBase = rtrim((string) config('app.frontend_url'), '/');
                $actionUrl = $frontendBase !== '' ? $frontendBase.'/dashboard' : null;

                $notificationDispatch->notifyUsersByUuids(
                    [$user->uuid],
                    new GenericDatabaseNotification(
                        module: ModuleEnums::authentication->value,
                        event: 'account.history_linked',
                        title: 'We connected your past giving history',
                        message: $this->buildHistoryLinkedMessage($result),
                        meta: [
                            'transactions' => (int) $result['transactions'],
                            'pledges' => (int) $result['pledges'],
                            'pledge_transactions' => (int) $result['pledge_transactions'],
                            'recognitions' => (int) $result['recognitions'],
                        ],
                        actionUrl: $actionUrl,
                        mailSubject: 'Your past donations are now linked to your account',
                        icon: '/icons/account-linked.png',
                        severity: 'info',
                        tags: ['account', 'history_linked'],
                        sendMail: true,
                    ),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to link guest donor history: '.$e->getMessage(), [
                'user_uuid' => $this->userUuid,
                'email' => $user->email,
            ]);

            throw $e;
        }
    }

    /**
     * @param  array{transactions: int, pledges: int, pledge_transactions: int, recognitions: int}  $result
     */
    private function buildHistoryLinkedMessage(array $result): string
    {
        $parts = [];

        if ($result['transactions'] > 0) {
            $parts[] = $this->pluralize($result['transactions'], 'past donation', 'past donations');
        }

        if ($result['pledges'] > 0) {
            $parts[] = $this->pluralize($result['pledges'], 'pledge', 'pledges');
        }

        if ($result['recognitions'] > 0) {
            $parts[] = $this->pluralize($result['recognitions'], 'recognition certificate', 'recognition certificates');
        }

        if ($parts === []) {
            return 'We linked your previous giving history to your new account.';
        }

        return 'We linked '.$this->joinWithAnd($parts).' to your new account.';
    }

    private function pluralize(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    /**
     * @param  list<string>  $parts
     */
    private function joinWithAnd(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }
}
