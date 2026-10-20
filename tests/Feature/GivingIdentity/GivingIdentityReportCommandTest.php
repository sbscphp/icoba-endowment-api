<?php

namespace Tests\Feature\GivingIdentity;

use App\Enums\DonorTypeSlug;
use App\Enums\GivingIdentitySource;
use App\Enums\GivingIdentityStatus;
use App\Enums\TransactionStatus;
use App\Mail\GivingIdentityReportMail;
use App\Models\Campaign;
use App\Models\DonorType;
use App\Models\GivingIdentity;
use App\Models\GraduationSet;
use App\Models\Theme;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Theme\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class GivingIdentityReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    private DonorType $alumni;

    private GraduationSet $set2002;

    private GraduationSet $set1998;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();

        $this->mock(ThemeResolver::class, function ($mock): void {
            $mock->shouldReceive('resolveForMail')->andReturn(new Theme(['brand_name' => 'Test', 'accent_color' => '#122168']));
        });

        foreach (DonorTypeSlug::cases() as $slug) {
            DonorType::query()->firstOrCreate(
                ['slug' => $slug->value],
                ['label' => $slug->label(), 'description' => $slug->description()],
            );
        }

        $this->alumni = DonorType::query()->where('slug', DonorTypeSlug::ICOBA_ALUMNI->value)->firstOrFail();
        $this->set2002 = GraduationSet::query()->create(['public_id' => 'SET-2002', 'name' => 'Class 2002', 'set_number' => '2002']);
        $this->set1998 = GraduationSet::query()->create(['public_id' => 'SET-1998', 'name' => 'Class 1998', 'set_number' => '1998']);

        $this->campaign = Campaign::query()->create([
            'campaign_id' => 'CMP-GI-001',
            'name' => 'Identity campaign',
            'short_description' => 'desc',
            'long_description' => 'desc',
            'categories' => ['general'],
            'base_currency' => 'NGN',
            'available_donation_currencies' => ['NGN'],
            'target_amount' => 10000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'allow_anonymous_donation' => true,
            'allow_public_donation' => true,
            'applies_to_all_graduation_sets' => true,
            'status' => 'active',
        ]);
    }

    public function test_sends_workbook_with_identities_users_and_unattached_donors(): void
    {
        // Linked account whose profile drifted from the locked identity.
        $linkedUser = User::factory()->create([
            'email' => 'ada@example.com',
            'firstname' => 'Ada',
            'lastname' => 'Okoye',
            'donor_type_uuid' => $this->alumni->uuid,
            'graduation_set_uuid' => $this->set1998->uuid,
        ]);
        $linked = $this->identity('ada@example.com', 'Ada', 'Okoye', GivingIdentitySource::REGISTRATION, GivingIdentityStatus::ACTIVE, $linkedUser->uuid, locked: true);
        $this->donation('TX-1', 'ada@example.com', 5000, $linked->uuid);
        $this->donation('TX-2', 'ada@example.com', 2500, $linked->uuid);
        $this->donation('TX-3', 'ada@example.com', 9999, $linked->uuid, TransactionStatus::FAILED);

        // Guest identity whose email also has an account that was never linked.
        User::factory()->create(['email' => 'guest@example.com', 'firstname' => 'Gbenga', 'lastname' => 'Bello']);
        $this->identity('guest@example.com', 'Gbenga', 'Bello', GivingIdentitySource::GUEST_CHECKOUT, GivingIdentityStatus::UNVERIFIED);

        // Registered user with no identity, and a donor email whose donation has no identity.
        $lonely = User::factory()->create(['email' => 'lonely@example.com']);
        $this->donation('TX-4', 'Orphan@Example.com ', 1200, null);

        $this->artisan('reports:giving-identities', ['--to' => ['ops@example.com, Second@Example.com']])
            ->assertSuccessful();

        $sent = null;
        Mail::assertSent(GivingIdentityReportMail::class, function (GivingIdentityReportMail $mail) use (&$sent): bool {
            $sent = $mail;

            return $mail->hasTo('ops@example.com') && $mail->hasTo('second@example.com');
        });

        $this->assertNotNull($sent);
        $this->assertStringEndsWith('.xlsx', $sent->filename);
        $this->assertSame(2, $sent->summary['total_identities']);
        $this->assertSame(1, $sent->summary['with_account']);
        $this->assertSame(7500.0, $sent->summary['total_ngn']);

        $sheets = $this->readWorkbook($sent->xlsxContents);
        $this->assertSame(['Summary', 'Giving identities', 'Users without identity', 'Donors without identity'], array_keys($sheets));

        $identities = $this->keyedRows($sheets['Giving identities']);
        $ada = $identities['ada@example.com'];
        $this->assertSame('registration', $ada['Source']);
        $this->assertSame('Created when the donor registered an account', $ada['How it was created']);
        $this->assertSame('Yes', $ada['Has account']);
        $this->assertSame($linkedUser->uuid, $ada['Account user UUID']);
        $this->assertSame('No', $ada['Account matches identity']);
        $this->assertSame('graduation set: identity "Set 2002", account "Set 1998"', $ada['Account vs identity differences']);
        $this->assertSame('2', $ada['Successful donations']);
        $this->assertSame('7500', $ada['Total donated (NGN)']);
        $this->assertSame('3', $ada['All payment attempts']);
        $this->assertSame('Yes', $ada['Locked']);
        $this->assertStringContainsString('Account profile differs', $ada['Needs attention']);

        $guest = $identities['guest@example.com'];
        $this->assertSame('guest_checkout', $guest['Source']);
        $this->assertSame('No', $guest['Has account']);
        $this->assertStringContainsString('An account exists for this email but is not linked', $guest['Needs attention']);

        $users = $this->keyedRows($sheets['Users without identity'], 'Email');
        $this->assertArrayHasKey('lonely@example.com', $users);
        $this->assertSame($lonely->uuid, $users['lonely@example.com']['User UUID']);
        $this->assertArrayHasKey('guest@example.com', $users);
        $this->assertNotSame('', $users['guest@example.com']['Unlinked identity on same email']);
        $this->assertArrayNotHasKey('ada@example.com', $users);

        $donors = $this->keyedRows($sheets['Donors without identity']);
        $this->assertSame('1200', $donors['orphan@example.com']['Total donated (NGN)']);
        $this->assertSame('No', $donors['orphan@example.com']['Identity exists for email']);

        $this->assertCount(1, Storage::disk('local')->files('reports/giving-identities'));
    }

    public function test_requires_a_recipient_unless_dry_run_or_save_only(): void
    {
        $this->artisan('reports:giving-identities')->assertExitCode(2);
        $this->artisan('reports:giving-identities', ['--to' => ['not-an-email']])->assertExitCode(2);
        $this->artisan('reports:giving-identities', ['--dry-run' => true])->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertCount(0, Storage::disk('local')->files('reports/giving-identities'));

        $this->artisan('reports:giving-identities', ['--save-only' => true])->assertSuccessful();
        $this->assertCount(1, Storage::disk('local')->files('reports/giving-identities'));
        Mail::assertNothingSent();
    }

    private function identity(
        string $email,
        string $firstname,
        string $lastname,
        GivingIdentitySource $source,
        GivingIdentityStatus $status,
        ?string $userUuid = null,
        bool $locked = false,
    ): GivingIdentity {
        return GivingIdentity::query()->create([
            'email_lower' => $email,
            'user_uuid' => $userUuid,
            'donor_type_uuid' => $this->alumni->uuid,
            'graduation_set_uuid' => $this->set2002->uuid,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'status' => $status,
            'source' => $source,
            'locked_at' => $locked ? now() : null,
        ]);
    }

    private function donation(string $id, string $email, float $naira, ?string $identityUuid, TransactionStatus $status = TransactionStatus::SUCCESSFUL): Transaction
    {
        return Transaction::query()->create([
            'transaction_id' => $id,
            'campaign_uuid' => $this->campaign->uuid,
            'giving_identity_uuid' => $identityUuid,
            'donor_name' => 'Donor '.$id,
            'donor_email' => $email,
            'donor_phone' => '08030000000',
            'amount' => $naira,
            'currency' => 'NGN',
            'amount_in_naira' => $naira,
            'status' => $status,
            'gateway' => 'paystack',
            'paid_at' => $status === TransactionStatus::SUCCESSFUL ? now() : null,
            'is_test' => false,
        ]);
    }

    /**
     * Reads every sheet of an xlsx produced by XlsxWriter (inline strings) into rows of cell text.
     *
     * @return array<string, list<list<string>>>
     */
    private function readWorkbook(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'Attachment is not a valid zip/xlsx.');

        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $sheets = [];
        $index = 1;

        foreach ($workbook->sheets->sheet as $sheet) {
            $xml = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet'.$index.'.xml'));
            $rows = [];

            foreach ($xml->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $cell) {
                    preg_match('/^([A-Z]+)/', (string) $cell['r'], $m);
                    $column = 0;
                    foreach (str_split($m[1]) as $letter) {
                        $column = $column * 26 + (ord($letter) - 64);
                    }
                    $cells[$column - 1] = isset($cell->is) ? (string) $cell->is->t : (string) $cell->v;
                }

                $width = $cells === [] ? 0 : max(array_keys($cells)) + 1;
                $rows[] = array_map(fn (int $i): string => $cells[$i] ?? '', range(0, max(0, $width - 1)));
            }

            $sheets[(string) $sheet['name']] = $rows;
            $index++;
        }

        $zip->close();
        @unlink($path);

        return $sheets;
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array<string, array<string, string>>
     */
    private function keyedRows(array $rows, string $keyColumn = 'Email'): array
    {
        $headers = array_shift($rows);
        $keyed = [];

        foreach ($rows as $row) {
            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[$header] = $row[$i] ?? '';
            }
            $keyed[$assoc[$keyColumn]] = $assoc;
        }

        return $keyed;
    }
}
