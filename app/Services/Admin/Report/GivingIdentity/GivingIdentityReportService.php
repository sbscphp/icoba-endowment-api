<?php

namespace App\Services\Admin\Report\GivingIdentity;

use App\Enums\GivingIdentitySource;
use App\Enums\GivingIdentityStatus;
use App\Enums\House;
use App\Enums\PledgeStatus;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Enums\WivesType;
use App\Mail\GivingIdentityReportMail;
use App\Models\CorporateCategory;
use App\Models\DonorType;
use App\Models\GivingIdentity;
use App\Models\GraduationSet;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use App\Services\GivingIdentity\GivingIdentityNormalizer;
use App\Services\GivingIdentity\GivingIdentityProfileBuilder;
use App\Services\Theme\ThemeResolver;
use App\Support\Spreadsheet\XlsxWriter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Snapshot of every giving identity on the system, the account it belongs to (if any),
 * how it was created, what it has given, and anything that needs an admin's attention.
 * Delivered as a multi-sheet .xlsx.
 *
 * Money totals are successful, revenue-countable transactions in naira (amount_in_naira),
 * matching the rest of the reporting. Test rows are included in totals, as elsewhere, but
 * are also counted separately so they can be told apart.
 */
final class GivingIdentityReportService
{
    public const IDENTITY_HEADERS = [
        'Identity UUID',
        'Email',
        'Display name',
        'Donor type',
        'Status',
        'What the status means',
        'Source',
        'How it was created',
        'Needs attention',
        'Has account',
        'Account user UUID',
        'Account name',
        'Account email',
        'Account phone',
        'Account donor type',
        'Account active',
        'Account email verified',
        'Account registered at',
        'Account matches identity',
        'Account vs identity differences',
        'First name',
        'Last name',
        'Organisation',
        'Corporate category',
        'RC number',
        'TIN',
        'Graduation set',
        'Alumni identifier',
        'House',
        'Affiliated set',
        'Wives of ICOBA chapter',
        'Igbobian owned',
        'Locked',
        'Locked at',
        'Successful donations',
        'Total donated (NGN)',
        'Currencies used',
        'First donation at',
        'Last donation at',
        'Test donations',
        'Test donated (NGN)',
        'All payment attempts',
        'Pledges',
        'Active pledges',
        'Pledged (NGN, excl. cancelled)',
        'Identity created at',
        'Identity updated at',
    ];

    public const USER_HEADERS = [
        'User UUID',
        'Name',
        'Email',
        'Phone',
        'Donor type',
        'Graduation set',
        'House',
        'Organisation',
        'Active',
        'Email verified',
        'Registered at',
        'Last login at',
        'Successful donations',
        'Total donated (NGN)',
        'Pledges',
        'Unlinked identity on same email',
        'Why it matters',
    ];

    public const DONOR_HEADERS = [
        'Email',
        'Donor name (latest)',
        'Donor phone (latest)',
        'Successful donations',
        'Total donated (NGN)',
        'All payment attempts',
        'Last activity at',
        'Has account',
        'Identity exists for email',
        'Why it matters',
    ];

    public function __construct(
        private readonly ThemeResolver $themeResolver,
    ) {}

    /**
     * @return array{
     *     generated_at: string,
     *     summary: array<string, mixed>,
     *     identities: list<list<mixed>>,
     *     users_without_identity: list<list<mixed>>,
     *     donors_without_identity: list<list<mixed>>,
     * }
     */
    public function build(): array
    {
        $lookups = $this->lookups();
        $transactionTotals = $this->transactionTotalsByIdentity();
        $attemptCounts = $this->attemptCountsByIdentity();
        $pledgeTotals = $this->pledgeTotalsByIdentity();

        $identityRows = [];
        $stats = [
            'by_status' => [],
            'by_source' => [],
            'by_donor_type' => [],
            'with_account' => 0,
            'locked' => 0,
            'needs_attention' => 0,
            'account_profile_differs' => 0,
            'total_ngn' => 0.0,
        ];

        GivingIdentity::query()
            ->with(['donorType', 'user.donorType'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $identities) use (
                &$identityRows,
                &$stats,
                $lookups,
                $transactionTotals,
                $attemptCounts,
                $pledgeTotals,
            ): void {
                foreach ($identities as $identity) {
                    $row = $this->identityRow(
                        $identity,
                        $lookups,
                        $transactionTotals[$identity->uuid] ?? null,
                        (int) ($attemptCounts[$identity->uuid] ?? 0),
                        $pledgeTotals[$identity->uuid] ?? null,
                    );

                    $identityRows[] = $row['cells'];

                    $status = $identity->status?->value ?? 'unknown';
                    $source = $identity->source?->value ?? 'unknown';
                    $type = $identity->donorType?->label ?? 'No donor type';

                    $stats['by_status'][$status] = ($stats['by_status'][$status] ?? 0) + 1;
                    $stats['by_source'][$source] = ($stats['by_source'][$source] ?? 0) + 1;
                    $stats['by_donor_type'][$type] = ($stats['by_donor_type'][$type] ?? 0) + 1;
                    $stats['with_account'] += $identity->user_uuid !== null ? 1 : 0;
                    $stats['locked'] += $identity->isLocked() ? 1 : 0;
                    $stats['needs_attention'] += $row['needs_attention'] ? 1 : 0;
                    $stats['account_profile_differs'] += $row['profile_differs'] ? 1 : 0;
                    $stats['total_ngn'] += (float) ($transactionTotals[$identity->uuid]->total_ngn ?? 0);
                }
            });

        $usersWithoutIdentity = $this->usersWithoutIdentity($lookups);
        $donorsWithoutIdentity = $this->donorsWithoutIdentity($lookups);

        $totalIdentities = count($identityRows);

        return [
            'generated_at' => now()->toDateTimeString(),
            'summary' => [
                'total_identities' => $totalIdentities,
                'with_account' => $stats['with_account'],
                'guest_only' => $totalIdentities - $stats['with_account'],
                'locked' => $stats['locked'],
                'needs_attention' => $stats['needs_attention'],
                'account_profile_differs' => $stats['account_profile_differs'],
                'total_ngn' => round($stats['total_ngn'], 2),
                'total_users' => User::query()->count(),
                'users_without_identity' => count($usersWithoutIdentity),
                'donor_emails_without_identity' => count($donorsWithoutIdentity),
                'by_status' => $stats['by_status'],
                'by_source' => $stats['by_source'],
                'by_donor_type' => $stats['by_donor_type'],
            ],
            'identities' => $identityRows,
            'users_without_identity' => $usersWithoutIdentity,
            'donors_without_identity' => $donorsWithoutIdentity,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function toXlsx(array $report): string
    {
        return (new XlsxWriter)
            ->addSheet('Summary', ['Item', 'Value', 'Explanation'], $this->summaryRows($report), [42, 18, 90])
            ->addSheet('Giving identities', self::IDENTITY_HEADERS, $report['identities'])
            ->addSheet('Users without identity', self::USER_HEADERS, $report['users_without_identity'])
            ->addSheet('Donors without identity', self::DONOR_HEADERS, $report['donors_without_identity'])
            ->toString();
    }

    /**
     * Build the workbook and email it.
     *
     * @param  list<string>  $recipients
     * @return array{recipients: list<string>, summary: array<string, mixed>, filename: string, stored_path: ?string}
     */
    public function generateAndSend(array $recipients, bool $store = true): array
    {
        $report = $this->build();
        $xlsx = $this->toXlsx($report);
        $filename = self::filename(now());

        Mail::to($recipients)->send(new GivingIdentityReportMail(
            summary: $report['summary'],
            generatedAt: $report['generated_at'],
            xlsxContents: $xlsx,
            filename: $filename,
            mailTheme: $this->themeResolver->resolveForMail(),
        ));

        return [
            'recipients' => $recipients,
            'summary' => $report['summary'],
            'filename' => $filename,
            'stored_path' => $store ? $this->store($filename, $xlsx) : null,
        ];
    }

    /**
     * Build the workbook and keep it on the reports disk without emailing it.
     *
     * @return array{summary: array<string, mixed>, filename: string, stored_path: ?string}
     */
    public function generateAndStore(): array
    {
        $report = $this->build();
        $xlsx = $this->toXlsx($report);
        $filename = self::filename(now());

        return [
            'summary' => $report['summary'],
            'filename' => $filename,
            'stored_path' => $this->store($filename, $xlsx),
        ];
    }

    public static function filename(CarbonInterface $at): string
    {
        return 'giving-identities-'.$at->format('Y-m-d-His').'.xlsx';
    }

    public static function statusMeaning(?GivingIdentityStatus $status): string
    {
        return match ($status) {
            GivingIdentityStatus::UNVERIFIED => 'Guest identity: has given or pledged by email, no account linked yet',
            GivingIdentityStatus::ACTIVE => 'Linked to a registered account',
            GivingIdentityStatus::CONFLICT => 'Conflicting donor profiles on this email; donations on it are blocked until support resolves it',
            GivingIdentityStatus::MERGED => 'Merged into another identity; kept for history only',
            null => 'No status recorded',
        };
    }

    public static function sourceMeaning(?GivingIdentitySource $source): string
    {
        return match ($source) {
            GivingIdentitySource::GUEST_CHECKOUT => 'Created at a donation checkout (guest, or a logged-in donor giving for the first time)',
            GivingIdentitySource::REGISTRATION => 'Created when the donor registered an account',
            GivingIdentitySource::ADMIN => 'Created by an admin maintenance command (identity backfill / reconcile)',
            GivingIdentitySource::RECONCILIATION => 'Created while an admin reconciled a bank transfer to a donor',
            GivingIdentitySource::PLEDGE => 'Created when a pledge was made',
            null => 'Unknown: created before sources were recorded',
        };
    }

    /**
     * @param  array<string, mixed>  $lookups
     * @return array{cells: list<mixed>, needs_attention: bool, profile_differs: bool}
     */
    private function identityRow(
        GivingIdentity $identity,
        array $lookups,
        ?object $transactions,
        int $attempts,
        ?object $pledges,
    ): array {
        $user = $identity->user;
        $existing = GivingIdentityProfileBuilder::fromIdentity($identity);

        $differences = $user !== null
            ? GivingIdentityProfileBuilder::fromUser($user)->hardFieldDifferencesFromIdentity($identity)
            : [];
        $profileDiffers = $differences !== [];

        $successfulCount = (int) ($transactions->successful_count ?? 0);

        $attention = [];
        if ($identity->status === GivingIdentityStatus::CONFLICT) {
            $attention[] = 'In conflict: support must resolve before this email can give again';
        }
        if ($user === null && $identity->email_lower !== null && isset($lookups['users_by_email'][$identity->email_lower])) {
            $attention[] = 'An account exists for this email but is not linked to this identity';
        }
        if ($profileDiffers) {
            $attention[] = 'Account profile differs from the identity (see differences)';
        }
        if ($user !== null && GivingIdentityNormalizer::email($user->email) !== $identity->email_lower) {
            $attention[] = 'Account email differs from the identity email';
        }
        if ($identity->isLocked() && $successfulCount === 0) {
            $attention[] = 'Locked but no successful donation is attached';
        }
        if ($identity->donor_type_uuid === null) {
            $attention[] = 'No donor type recorded';
        }

        $cells = [
            $identity->uuid,
            $identity->email_lower,
            $existing->displayLabel(),
            $identity->donorType?->label ?? $existing->donorTypeLabel(),
            $identity->status?->value,
            self::statusMeaning($identity->status),
            $identity->source?->value ?? 'unknown',
            self::sourceMeaning($identity->source),
            $attention !== [] ? implode('; ', $attention) : '',
            $user !== null,
            $user?->uuid,
            $user !== null ? $this->personName($user->firstname, $user->lastname) : null,
            $user?->email,
            $user?->phone_number,
            $user?->donorType?->label,
            $user !== null ? (bool) $user->is_active : null,
            $user !== null ? $user->email_verified_at !== null : null,
            $this->date($user?->created_at),
            $user !== null ? ! $profileDiffers : null,
            $this->differencesText($differences, $lookups),
            $identity->firstname,
            $identity->lastname,
            $identity->organization_name,
            $lookups['categories'][$identity->corporate_category_uuid] ?? null,
            $identity->rc_number,
            $identity->tin,
            $this->setLabel($identity->graduation_set_uuid, $lookups),
            $identity->alumni_identifier,
            $this->houseLabel($identity->house),
            $this->setLabel($identity->affiliated_graduation_set_uuid, $lookups),
            $this->wivesLabel($identity->wives_type),
            (bool) $identity->is_igbobian_owned,
            $identity->isLocked(),
            $this->date($identity->locked_at),
            $successfulCount,
            round((float) ($transactions->total_ngn ?? 0), 2),
            $transactions->currencies ?? null,
            $this->date($transactions->first_at ?? null),
            $this->date($transactions->last_at ?? null),
            (int) ($transactions->test_count ?? 0),
            round((float) ($transactions->test_ngn ?? 0), 2),
            $attempts,
            (int) ($pledges->pledge_count ?? 0),
            (int) ($pledges->active_count ?? 0),
            round((float) ($pledges->committed_ngn ?? 0), 2),
            $this->date($identity->created_at),
            $this->date($identity->updated_at),
        ];

        return [
            'cells' => $cells,
            'needs_attention' => $attention !== [],
            'profile_differs' => $profileDiffers,
        ];
    }

    /**
     * @param  array<string, mixed>  $lookups
     * @return list<list<mixed>>
     */
    private function usersWithoutIdentity(array $lookups): array
    {
        $linkedUserUuids = GivingIdentity::query()->whereNotNull('user_uuid')->select('user_uuid');

        $donationTotals = Transaction::query()
            ->countableTowardRevenue()
            ->whereNotNull('user_uuid')
            ->groupBy('user_uuid')
            ->selectRaw('user_uuid, COUNT(*) as successful_count, SUM(amount_in_naira) as total_ngn')
            ->get()
            ->keyBy('user_uuid');

        $pledgeCounts = Pledge::query()
            ->whereNotNull('user_uuid')
            ->groupBy('user_uuid')
            ->selectRaw('user_uuid, COUNT(*) as pledge_count')
            ->pluck('pledge_count', 'user_uuid');

        $rows = [];

        User::query()
            ->with('donorType')
            ->whereNotIn('uuid', $linkedUserUuids)
            ->orderBy('id')
            ->chunkById(500, function (Collection $users) use (&$rows, $lookups, $donationTotals, $pledgeCounts): void {
                foreach ($users as $user) {
                    $email = GivingIdentityNormalizer::email($user->email);
                    $unlinkedIdentity = $email !== null ? ($lookups['identities_by_email'][$email] ?? null) : null;
                    $totals = $donationTotals[$user->uuid] ?? null;
                    $successful = (int) ($totals->successful_count ?? 0);

                    $why = match (true) {
                        $unlinkedIdentity !== null => 'Guest giving history exists on this email but was never linked to the account',
                        $successful > 0 => 'Has donated but no identity was created; tier and totals may be incomplete',
                        default => 'Registered but no identity yet; one is created on first donation',
                    };

                    $rows[] = [
                        $user->uuid,
                        $this->personName($user->firstname, $user->lastname),
                        $user->email,
                        $user->phone_number,
                        $user->donorType?->label,
                        $this->setLabel($user->graduation_set_uuid, $lookups),
                        $this->houseLabel($user->house),
                        $user->organization_name,
                        (bool) $user->is_active,
                        $user->email_verified_at !== null,
                        $this->date($user->created_at),
                        $this->date($user->last_login_at),
                        $successful,
                        round((float) ($totals->total_ngn ?? 0), 2),
                        (int) ($pledgeCounts[$user->uuid] ?? 0),
                        $unlinkedIdentity,
                        $why,
                    ];
                }
            });

        return $rows;
    }

    /**
     * Donation emails that have transactions with no giving identity attached.
     *
     * @param  array<string, mixed>  $lookups
     * @return list<list<mixed>>
     */
    private function donorsWithoutIdentity(array $lookups): array
    {
        // Same rule as Transaction::scopeCountableTowardRevenue, inlined so failed attempts can be counted in the same pass.
        $countable = '(status = ? AND (application_type IS NULL OR application_type != ?))';
        $bindings = [TransactionStatus::SUCCESSFUL->value, TransactionApplicationType::PLEDGE_PLACEHOLDER->value];

        $groups = Transaction::query()
            ->whereNull('giving_identity_uuid')
            ->selectRaw("LOWER(TRIM(COALESCE(donor_email, ''))) as email_lower")
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN '.$countable.' THEN 1 ELSE 0 END) as successful_count', $bindings)
            ->selectRaw('SUM(CASE WHEN '.$countable.' THEN amount_in_naira ELSE 0 END) as total_ngn', $bindings)
            ->selectRaw('MAX(COALESCE(paid_at, created_at)) as last_at')
            ->selectRaw('MAX(id) as latest_id')
            ->groupBy(DB::raw("LOWER(TRIM(COALESCE(donor_email, '')))"))
            ->orderByDesc('total_ngn')
            ->get();

        $latest = Transaction::query()
            ->whereIn('id', $groups->pluck('latest_id')->all())
            ->get(['id', 'donor_name', 'donor_phone'])
            ->keyBy('id');

        $rows = [];

        foreach ($groups as $group) {
            $email = $group->email_lower !== '' ? (string) $group->email_lower : null;
            $hasAccount = $email !== null && isset($lookups['users_by_email'][$email]);
            $identityExists = $email !== null && isset($lookups['all_identity_emails'][$email]);
            $successful = (int) $group->successful_count;
            $latestRow = $latest[$group->latest_id] ?? null;

            $why = match (true) {
                $email === null => 'No email captured, so these donations cannot be tied to any donor',
                $identityExists => 'An identity exists for this email; these older donations were never attached to it',
                $successful > 0 => 'Successful donations with no identity; run the giving identity reconcile command',
                default => 'Only unsuccessful attempts; no action needed unless the donor follows up',
            };

            $rows[] = [
                $email ?? '(no email)',
                $latestRow?->donor_name,
                $latestRow?->donor_phone,
                $successful,
                round((float) $group->total_ngn, 2),
                (int) $group->attempts,
                $this->date($group->last_at),
                $hasAccount,
                $identityExists,
                $why,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, object>
     */
    private function transactionTotalsByIdentity(): array
    {
        return Transaction::query()
            ->countableTowardRevenue()
            ->whereNotNull('giving_identity_uuid')
            ->groupBy('giving_identity_uuid')
            ->selectRaw('giving_identity_uuid')
            ->selectRaw('COUNT(*) as successful_count')
            ->selectRaw('SUM(amount_in_naira) as total_ngn')
            ->selectRaw('MIN(COALESCE(paid_at, created_at)) as first_at')
            ->selectRaw('MAX(COALESCE(paid_at, created_at)) as last_at')
            ->selectRaw('SUM(CASE WHEN is_test = 1 THEN 1 ELSE 0 END) as test_count')
            ->selectRaw('SUM(CASE WHEN is_test = 1 THEN amount_in_naira ELSE 0 END) as test_ngn')
            ->selectRaw('GROUP_CONCAT(DISTINCT currency) as currencies')
            ->get()
            ->keyBy('giving_identity_uuid')
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function attemptCountsByIdentity(): array
    {
        return Transaction::query()
            ->whereNotNull('giving_identity_uuid')
            ->groupBy('giving_identity_uuid')
            ->selectRaw('giving_identity_uuid, COUNT(*) as attempts')
            ->pluck('attempts', 'giving_identity_uuid')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @return array<string, object>
     */
    private function pledgeTotalsByIdentity(): array
    {
        return Pledge::query()
            ->whereNotNull('giving_identity_uuid')
            ->groupBy('giving_identity_uuid')
            ->selectRaw('giving_identity_uuid')
            ->selectRaw('COUNT(*) as pledge_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active_count', [PledgeStatus::ACTIVE->value])
            ->selectRaw('SUM(CASE WHEN status != ? THEN committed_amount_ngn ELSE 0 END) as committed_ngn', [PledgeStatus::CANCELLED->value])
            ->get()
            ->keyBy('giving_identity_uuid')
            ->all();
    }

    /**
     * Small reference tables loaded once so rows can show set numbers and names instead of uuids.
     *
     * @return array<string, mixed>
     */
    private function lookups(): array
    {
        $usersByEmail = [];
        User::query()->select(['id', 'uuid', 'email'])->orderBy('id')->chunkById(1000, function (Collection $users) use (&$usersByEmail): void {
            foreach ($users as $user) {
                $email = GivingIdentityNormalizer::email($user->email);
                if ($email !== null) {
                    $usersByEmail[$email] = $user->uuid;
                }
            }
        });

        return [
            'sets' => GraduationSet::query()->pluck('set_number', 'uuid')->all(),
            'categories' => CorporateCategory::query()->pluck('name', 'uuid')->all(),
            'donor_types' => DonorType::query()->pluck('label', 'uuid')->all(),
            'users_by_email' => $usersByEmail,
            'identities_by_email' => GivingIdentity::query()
                ->whereNull('user_uuid')
                ->whereNotNull('email_lower')
                ->pluck('uuid', 'email_lower')
                ->all(),
            'all_identity_emails' => GivingIdentity::query()
                ->whereNotNull('email_lower')
                ->pluck('uuid', 'email_lower')
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<mixed>>
     */
    private function summaryRows(array $report): array
    {
        $s = $report['summary'];

        $rows = [
            ['Generated at', $report['generated_at'], 'Server time when this snapshot was taken'],
            ['', '', ''],
            ['Giving identities', $s['total_identities'], 'One per donor email. Every donation and pledge is attributed to an identity'],
            ['  linked to an account', $s['with_account'], 'Donor has registered and the identity is tied to their account'],
            ['  guest only', $s['guest_only'], 'Donor has given by email but has no linked account'],
            ['  locked', $s['locked'], 'Profile frozen by a successful donation; donor type, set and name can no longer change'],
            ['  needing attention', $s['needs_attention'], 'See the "Needs attention" column on the Giving identities sheet'],
            ['  account profile differs', $s['account_profile_differs'], 'Linked account and identity disagree on donor type, set, name or organisation'],
            ['Total donated via identities (NGN)', $s['total_ngn'], 'Successful donations attached to an identity, in naira, test donations included'],
            ['', '', ''],
            ['Registered users', $s['total_users'], 'All customer accounts'],
            ['  without an identity', $s['users_without_identity'], 'Listed on the Users without identity sheet'],
            ['Donor emails with unattached donations', $s['donor_emails_without_identity'], 'Listed on the Donors without identity sheet'],
            ['', '', ''],
            ['BY STATUS', '', ''],
        ];

        foreach (GivingIdentityStatus::cases() as $status) {
            $rows[] = ['  '.$status->value, (int) ($s['by_status'][$status->value] ?? 0), self::statusMeaning($status)];
        }

        $rows[] = ['', '', ''];
        $rows[] = ['BY SOURCE (how the identity was created)', '', ''];

        foreach (GivingIdentitySource::cases() as $source) {
            $rows[] = ['  '.$source->value, (int) ($s['by_source'][$source->value] ?? 0), self::sourceMeaning($source)];
        }
        if (isset($s['by_source']['unknown'])) {
            $rows[] = ['  unknown', (int) $s['by_source']['unknown'], self::sourceMeaning(null)];
        }

        $rows[] = ['', '', ''];
        $rows[] = ['BY DONOR TYPE', '', ''];

        $byType = $s['by_donor_type'];
        arsort($byType);
        foreach ($byType as $label => $count) {
            $rows[] = ['  '.$label, (int) $count, ''];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{existing: mixed, incoming: mixed}>  $differences
     * @param  array<string, mixed>  $lookups
     */
    private function differencesText(array $differences, array $lookups): string
    {
        $labels = [
            'donor_type_uuid' => 'donor type',
            'firstname' => 'first name',
            'lastname' => 'last name',
            'graduation_set_uuid' => 'graduation set',
            'corporate_category_uuid' => 'corporate category',
            'organization_name' => 'organisation',
        ];

        $parts = [];

        foreach ($labels as $field => $label) {
            if (! isset($differences[$field])) {
                continue;
            }

            [$identityValue, $accountValue] = [$differences[$field]['existing'], $differences[$field]['incoming']];

            if ($field === 'donor_type_uuid') {
                [$identityValue, $accountValue] = [$lookups['donor_types'][$identityValue] ?? $identityValue, $lookups['donor_types'][$accountValue] ?? $accountValue];
            } elseif ($field === 'graduation_set_uuid') {
                [$identityValue, $accountValue] = [$this->setLabel($identityValue, $lookups), $this->setLabel($accountValue, $lookups)];
            } elseif ($field === 'corporate_category_uuid') {
                [$identityValue, $accountValue] = [$lookups['categories'][$identityValue] ?? $identityValue, $lookups['categories'][$accountValue] ?? $accountValue];
            }

            $parts[] = sprintf('%s: identity "%s", account "%s"', $label, $identityValue ?? 'none', $accountValue ?? 'none');
        }

        return implode('; ', $parts);
    }

    /**
     * @param  array<string, mixed>  $lookups
     */
    private function setLabel(?string $uuid, array $lookups): ?string
    {
        if ($uuid === null) {
            return null;
        }

        $number = $lookups['sets'][$uuid] ?? null;

        return $number !== null ? 'Set '.$number : $uuid;
    }

    private function houseLabel(?string $house): ?string
    {
        $normalized = House::normalize($house);

        return $normalized !== null ? (House::tryFrom($normalized)?->label() ?? $normalized) : null;
    }

    private function wivesLabel(?string $wivesType): ?string
    {
        $normalized = WivesType::normalize($wivesType);

        return $normalized !== null ? (WivesType::tryFrom($normalized)?->label() ?? $normalized) : null;
    }

    private function personName(?string $firstname, ?string $lastname): ?string
    {
        $name = trim(implode(' ', array_filter([$firstname, $lastname])));

        return $name !== '' ? $name : null;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i');
        }

        return substr((string) $value, 0, 16);
    }

    private function store(string $filename, string $xlsx): ?string
    {
        $disk = (string) config('reports.giving_identities.storage_disk', 'local');
        $directory = trim((string) config('reports.giving_identities.storage_path', 'reports/giving-identities'), '/');
        $path = $directory.'/'.$filename;

        try {
            Storage::disk($disk)->put($path, $xlsx);

            return $path;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
