<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Maintenance\UserDeletionService;
use Illuminate\Console\Command;

class DeleteUserCommand extends Command
{
    protected $signature = 'users:delete
                            {uuid : UUID of the customer account to delete}
                            {--dry-run : Only report what would be deleted}
                            {--force : Required to delete in production}';

    protected $description = 'Permanently delete a customer account with its pledges, transactions, receipts, tier recognitions, OTPs, tokens, notifications and giving identity.';

    // # Preview (nothing is deleted)
    // php artisan users:delete 9f1c... --dry-run

    // # Delete (local / staging)
    // php artisan users:delete 9f1c...

    // # Delete in production
    // php artisan users:delete 9f1c... --force

    public function handle(UserDeletionService $service): int
    {
        $uuid = trim((string) $this->argument('uuid'));
        $user = User::query()->where('uuid', $uuid)->first();

        if ($user === null) {
            $this->error('No user found with uuid "'.$uuid.'".');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && $this->laravel->environment('production') && ! (bool) $this->option('force')) {
            $this->warn('Production: nothing will be deleted without --force.');
            $dryRun = true;
        }

        $this->line('User: '.$user->displayName().' <'.$user->email.'> — '
            .($user->email_verified_at === null ? 'unverified' : 'verified')
            .', registered '.$user->created_at?->toDateTimeString());

        $result = $service->delete($user, $dryRun);

        $this->table(['Record', $dryRun ? 'Would delete' : 'Deleted'], [
            ['User', '1'],
            ['Transactions (incl. every payment on the user\'s pledges)', (string) $result['transactions']],
            ['Transaction receipts', (string) $result['receipts']],
            ['Tier recognitions', (string) $result['recognitions']],
            ['Pledges', (string) $result['pledges']],
            ['Giving identities', (string) $result['giving_identities']],
            ['One-time passwords', (string) $result['otps']],
            ['Auth challenges', (string) $result['auth_challenges']],
            ['API tokens', (string) $result['tokens']],
            ['Sessions', (string) $result['sessions']],
            ['Password reset tokens', (string) $result['password_reset_tokens']],
            ['Notifications', (string) $result['notifications']],
        ]);

        if ($result['giving_identities_kept'] > 0) {
            $this->warn('Giving identity kept (unlinked from the user): other donations with this email still use it.');
        }

        if ($result['other_pledges_refreshed'] > 0) {
            $this->line('Pledges of other donors with status recomputed: '.$result['other_pledges_refreshed']);
        }

        if ($dryRun) {
            $this->warn('Nothing was deleted.'.($this->option('dry-run') ? '' : ' Re-run with --force to delete.'));

            return (bool) $this->option('dry-run') ? self::SUCCESS : self::FAILURE;
        }

        $this->info('User deleted.');

        return self::SUCCESS;
    }
}
