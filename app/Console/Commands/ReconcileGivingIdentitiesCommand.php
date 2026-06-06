<?php

namespace App\Console\Commands;

use App\Enums\GivingIdentitySource;
use App\Enums\GivingIdentityStatus;
use App\Enums\TransactionStatus;
use App\Models\GivingIdentity;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use App\Services\GivingIdentity\GivingIdentityNormalizer;
use App\Services\GivingIdentity\GivingIdentityProfile;
use App\Services\GivingIdentity\GivingIdentityProfileBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ReconcileGivingIdentitiesCommand extends Command
{
    protected $signature = 'giving-identities:reconcile
                            {--dry-run : Analyse and report without writing changes}
                            {--limit=0 : Maximum number of email groups to process (0 = all)}';

    protected $description = 'Backfill giving identities from historical transactions and pledges.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $emails = $this->collectDistinctEmails();
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

        foreach ($emails as $email) {
            $result = $this->reconcileEmailGroup($email, $dryRun);
            $created += $result['created'];
            $stamped += $result['stamped'];
            $conflicts += $result['conflicts'];
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Identities created', (string) $created],
                ['Records stamped', (string) $stamped],
                ['Conflict groups', (string) $conflicts],
            ],
        );

        if ($dryRun) {
            $this->warn('Dry run complete. Re-run without --dry-run to apply changes.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, string>
     */
    private function collectDistinctEmails(): Collection
    {
        $fromTransactions = Transaction::query()
            ->whereNotNull('donor_email')
            ->where('donor_email', '!=', '')
            ->selectRaw('LOWER(TRIM(donor_email)) as email_lower')
            ->distinct()
            ->pluck('email_lower');

        $fromPledges = Pledge::query()
            ->whereNotNull('donor_email')
            ->where('donor_email', '!=', '')
            ->selectRaw('LOWER(TRIM(donor_email)) as email_lower')
            ->distinct()
            ->pluck('email_lower');

        return $fromTransactions->merge($fromPledges)->filter()->unique()->sort()->values();
    }

    /**
     * @return array{created: int, stamped: int, conflicts: int}
     */
    private function reconcileEmailGroup(string $emailLower, bool $dryRun): array
    {
        $profiles = $this->profilesForEmail($emailLower);
        if ($profiles->isEmpty()) {
            return ['created' => 0, 'stamped' => 0, 'conflicts' => 0];
        }

        $user = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$emailLower])->first();
        $canonical = $this->chooseCanonicalProfile($profiles, $user);

        $existingIdentity = GivingIdentity::query()->where('email_lower', $emailLower)->first();
        $hasConflict = $profiles->contains(fn (GivingIdentityProfile $profile): bool => ! $canonical->hardFieldsMatch($profile));

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
            return ['created' => $created, 'stamped' => 0, 'conflicts' => $hasConflict ? 1 : 0];
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
        ];
    }

    /**
     * @return Collection<int, GivingIdentityProfile>
     */
    private function profilesForEmail(string $emailLower): Collection
    {
        $profiles = collect();

        Transaction::query()
            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
            ->orderBy('id')
            ->chunkById(200, function ($transactions) use (&$profiles): void {
                foreach ($transactions as $transaction) {
                    $profile = $this->profileFromTransaction($transaction);
                    if ($profile !== null) {
                        $profiles->push($profile);
                    }
                }
            });

        Pledge::query()
            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
            ->orderBy('id')
            ->chunkById(200, function ($pledges) use (&$profiles): void {
                foreach ($pledges as $pledge) {
                    $profiles->push(GivingIdentityProfileBuilder::fromPledge($pledge));
                }
            });

        return $profiles
            ->unique(fn (GivingIdentityProfile $profile): string => json_encode([
                $profile->donorTypeUuid,
                $profile->graduationSetUuid,
                $profile->corporateCategoryUuid,
                GivingIdentityNormalizer::text($profile->organizationName),
                GivingIdentityNormalizer::text($profile->firstname),
                GivingIdentityNormalizer::text($profile->lastname),
            ]))
            ->values();
    }

    /**
     * @param  Collection<int, GivingIdentityProfile>  $profiles
     */
    private function chooseCanonicalProfile(Collection $profiles, ?User $user): GivingIdentityProfile
    {
        if ($user !== null) {
            return GivingIdentityProfileBuilder::fromUser($user);
        }

        return $profiles->first();
    }

    private function profileFromTransaction(Transaction $transaction): ?GivingIdentityProfile
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $guestProfile = is_array($metadata['guest_donor_profile'] ?? null) ? $metadata['guest_donor_profile'] : [];

        if ($guestProfile === [] && $transaction->donor_type_uuid === null) {
            return null;
        }

        return GivingIdentityProfileBuilder::fromGuestPayload([
            'donor_type_uuid' => $transaction->donor_type_uuid,
            'organization_name' => $transaction->organization_name,
            'rc_number' => $transaction->rc_number,
            'tin' => $transaction->tin,
        ], [
            'donor_type_uuid' => $transaction->donor_type_uuid,
            'guest_donor_profile' => $guestProfile,
        ]);
    }

    private function emailHasSuccessfulPayment(string $emailLower): bool
    {
        return Transaction::query()
            ->where('status', TransactionStatus::SUCCESSFUL)
            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
            ->exists();
    }
}
