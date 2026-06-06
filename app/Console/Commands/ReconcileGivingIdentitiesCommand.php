<?php

namespace App\Console\Commands;

use App\Enums\GivingIdentitySource;
use App\Enums\GivingIdentityStatus;
use App\Enums\TransactionStatus;
use App\Models\GivingIdentity;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Services\GivingIdentity\GivingIdentityConflictAnalyzer;
use App\Services\GivingIdentity\GivingIdentityConflictResolver;
use App\Services\GivingIdentity\GivingIdentityProfile;
use App\Services\GivingIdentity\GivingIdentityProfileBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ReconcileGivingIdentitiesCommand extends Command
{
    protected $signature = 'giving-identities:reconcile
                            {--dry-run : Analyse and report without writing changes}
                            {--limit=0 : Maximum number of email groups to process (0 = all)}
                            {--email= : Process a single donor email only}
                            {--resolve-conflicts : Align all records for conflicting emails to the tied giving identity profile}';

    protected $description = 'Backfill giving identities from historical transactions and pledges.';

    public function __construct(
        private readonly GivingIdentityConflictAnalyzer $analyzer,
        private readonly GivingIdentityConflictResolver $conflictResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $resolveConflicts = (bool) $this->option('resolve-conflicts');
        $limit = max(0, (int) $this->option('limit'));
        $emailFilter = is_string($this->option('email')) ? trim($this->option('email')) : null;

        if ($resolveConflicts && $dryRun) {
            $this->warn('--resolve-conflicts with --dry-run will only report how many records would be updated.');
        }

        $emails = $this->analyzer->collectDistinctEmails($emailFilter !== '' ? $emailFilter : null);
        if ($limit > 0) {
            $emails = $emails->take($limit);
        }

        $this->info(sprintf(
            '%s processing %d distinct donor email(s)...',
            $dryRun ? '[DRY RUN]' : 'Reconciling',
            $emails->count(),
        ));

        $created = 0;
        $stamped = 0;
        $conflicts = 0;
        $resolved = 0;
        $recordsAligned = 0;

        foreach ($emails as $email) {
            $result = $this->reconcileEmailGroup($email, $dryRun);
            $created += $result['created'];
            $stamped += $result['stamped'];
            $conflicts += $result['conflicts'];

            if ($resolveConflicts && ($result['conflicts'] > 0 || $result['identity_status'] === GivingIdentityStatus::CONFLICT->value)) {
                $identity = $this->analyzer->identityForEmail($email);
                if ($identity !== null) {
                    $resolveResult = $this->conflictResolver->resolveForIdentity($identity, $dryRun);
                    $resolved++;
                    $recordsAligned += $resolveResult['transactions'] + $resolveResult['pledges'];

                    if (! $dryRun) {
                        $this->line("  Resolved {$email}: {$resolveResult['transactions']} transaction(s), {$resolveResult['pledges']} pledge(s).");
                    }
                }
            }
        }

        $this->newLine();
        $rows = [
            ['Identities created', (string) $created],
            ['Records stamped', (string) $stamped],
            ['Conflict groups', (string) $conflicts],
        ];

        if ($resolveConflicts) {
            $rows[] = ['Conflict groups processed', (string) $resolved];
            $rows[] = ['Records aligned to identity', (string) $recordsAligned];
        }

        $this->table(['Metric', 'Count'], $rows);

        if ($dryRun) {
            $this->warn('Dry run complete. Re-run without --dry-run to apply changes.');
        }

        if ($conflicts > 0 && ! $resolveConflicts) {
            $this->warn('Conflict groups detected. Run with --resolve-conflicts to align records to the tied giving identity.');
            $this->line('Or inspect first: php artisan giving-identities:report-conflicts');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{created: int, stamped: int, conflicts: int, identity_status: ?string}
     */
    private function reconcileEmailGroup(string $emailLower, bool $dryRun): array
    {
        $analysis = $this->analyzer->analyzeEmail($emailLower);
        $observedProfiles = $analysis['observed_profiles'];

        if ($observedProfiles->isEmpty() && $analysis['identity_uuid'] === null) {
            return ['created' => 0, 'stamped' => 0, 'conflicts' => 0, 'identity_status' => null];
        }

        $user = $this->analyzer->userForEmail($emailLower);
        $canonical = $analysis['canonical'] ?? $this->chooseCanonicalProfile($observedProfiles, $user);
        if ($canonical === null) {
            return ['created' => 0, 'stamped' => 0, 'conflicts' => 0, 'identity_status' => null];
        }

        $existingIdentity = $this->analyzer->identityForEmail($emailLower);
        $hasConflict = $analysis['has_conflict'];

        if ($hasConflict) {
            $this->warn("Conflict for {$emailLower} (".($user?->uuid ?? 'guest').')');
        }

        $created = 0;
        $stamped = 0;

        if ($existingIdentity === null) {
            if ($dryRun) {
                return [
                    'created' => 1,
                    'stamped' => 0,
                    'conflicts' => $hasConflict ? 1 : 0,
                    'identity_status' => $hasConflict ? GivingIdentityStatus::CONFLICT->value : null,
                ];
            }

            $existingIdentity = GivingIdentity::query()->create(array_merge(
                $canonical->toIdentityAttributes($emailLower),
                [
                    'user_uuid' => $user?->uuid,
                    'status' => $hasConflict
                        ? GivingIdentityStatus::CONFLICT
                        : ($user !== null ? GivingIdentityStatus::ACTIVE : GivingIdentityStatus::UNVERIFIED),
                    'source' => GivingIdentitySource::ADMIN,
                    'locked_at' => $this->emailHasSuccessfulPayment($emailLower) ? now() : null,
                ],
            ));
            $created = 1;
        } elseif (! $dryRun) {
            if ($user !== null && $existingIdentity->user_uuid === null) {
                $existingIdentity->forceFill(['user_uuid' => $user->uuid])->save();
            }
            if ($hasConflict && $existingIdentity->status !== GivingIdentityStatus::CONFLICT) {
                $existingIdentity->forceFill(['status' => GivingIdentityStatus::CONFLICT])->save();
            }
        }

        if ($dryRun) {
            return [
                'created' => $created,
                'stamped' => 0,
                'conflicts' => $hasConflict ? 1 : 0,
                'identity_status' => $existingIdentity?->status?->value,
            ];
        }

        $stamped += Transaction::query()
            ->whereNull('giving_identity_uuid')
            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
            ->update(['giving_identity_uuid' => $existingIdentity->uuid]);

        $stamped += Pledge::query()
            ->whereNull('giving_identity_uuid')
            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
            ->update(['giving_identity_uuid' => $existingIdentity->uuid]);

        return [
            'created' => $created,
            'stamped' => $stamped,
            'conflicts' => $hasConflict ? 1 : 0,
            'identity_status' => $existingIdentity->status?->value,
        ];
    }

    /**
     * @param  Collection<int, GivingIdentityProfile>  $profiles
     */
    private function chooseCanonicalProfile(Collection $profiles, ?\App\Models\User $user): ?GivingIdentityProfile
    {
        if ($user !== null) {
            return GivingIdentityProfileBuilder::fromUser($user);
        }

        return $profiles->first();
    }

    private function emailHasSuccessfulPayment(string $emailLower): bool
    {
        return Transaction::query()
            ->where('status', TransactionStatus::SUCCESSFUL)
            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
            ->exists();
    }
}
