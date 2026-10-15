<?php

namespace Tests\Feature\GivingIdentity;

use App\Enums\DonorTypeSlug;
use App\Enums\GivingIdentitySource;
use App\Enums\GivingIdentityStatus;
use App\Models\DonorType;
use App\Models\GivingIdentity;
use App\Models\GraduationSet;
use App\Models\User;
use App\Services\GivingIdentity\GivingIdentityFlagLogger;
use App\Services\GivingIdentity\GivingIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GivingIdentityFlagLogTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    private DonorType $alumni;

    private DonorType $friend;

    private GraduationSet $set2002;

    private GraduationSet $set1998;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = storage_path('logs/testing-giving-identity-'.uniqid().'.log');
        config([
            'logging.channels.giving_identity.driver' => 'single',
            'logging.channels.giving_identity.path' => $this->logPath,
        ]);

        foreach (DonorTypeSlug::cases() as $slug) {
            DonorType::query()->firstOrCreate(
                ['slug' => $slug->value],
                ['label' => $slug->label(), 'description' => $slug->description()],
            );
        }

        $this->alumni = DonorType::query()->where('slug', DonorTypeSlug::ICOBA_ALUMNI->value)->firstOrFail();
        $this->friend = DonorType::query()->where('slug', DonorTypeSlug::FRIENDS_OF_ICOBA->value)->firstOrFail();

        $this->set2002 = GraduationSet::query()->create(['public_id' => 'SET-2002', 'name' => 'Class 2002', 'set_number' => '2002']);
        $this->set1998 = GraduationSet::query()->create(['public_id' => 'SET-1998', 'name' => 'Class 1998', 'set_number' => '1998']);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            @unlink($this->logPath);
        }

        parent::tearDown();
    }

    public function test_registration_against_locked_identity_logs_user_and_differences(): void
    {
        $identity = $this->lockedAlumniIdentity('john.doe@example.com', 'John', 'Doe', $this->set2002);

        $user = User::factory()->create([
            'email' => 'John.Doe@example.com',
            'firstname' => 'Jonathan',
            'lastname' => 'Doe',
            'donor_type_uuid' => $this->alumni->uuid,
            'graduation_set_uuid' => $this->set1998->uuid,
        ]);

        try {
            app(GivingIdentityResolver::class)->linkRegistrationToIdentity($user);
            $this->fail('Expected the registration to be flagged.');
        } catch (ValidationException) {
            // expected
        }

        // Note: the CONFLICT status written by the resolver is rolled back with the transaction
        // when the ValidationException propagates, so the log entry is the durable record.
        $entry = $this->lastLogEntry();

        $this->assertSame(
            'REGISTRATION BLOCKED for john.doe@example.com'
            .' | who: Jonathan Doe (registered user '.$user->uuid.', John.Doe@example.com) as ICOBA Member Set 1998'
            .' | existing: John Doe (ICOBA Member Set 2002, identity '.$identity->uuid.', no account, locked by a successful donation)'
            .' | differs on: first name "John" -> "Jonathan"; graduation set "2002" -> "1998"'
            .' | why: profile does not match the locked identity; identity marked CONFLICT, user told to contact support',
            $entry['message'],
        );
        $this->assertSame('giving_identity.flagged', $entry['context']['event']);
        $this->assertSame(GivingIdentityFlagLogger::FLOW_REGISTRATION, $entry['context']['flow']);
        $this->assertSame(GivingIdentitySource::REGISTRATION->value, $entry['context']['source']);
        $this->assertSame('john.doe@example.com', $entry['context']['email']);

        $this->assertSame($user->uuid, $entry['context']['user']['uuid']);
        $this->assertSame('Jonathan Doe', $entry['context']['user']['name']);
        $this->assertSame('icoba_alumni', $entry['context']['user']['donor_type']);

        $this->assertSame($identity->uuid, $entry['context']['identity']['uuid']);
        $this->assertSame('John Doe', $entry['context']['identity']['label']);
        $this->assertNotNull($entry['context']['identity']['locked_at']);

        $differences = $entry['context']['differences'];
        $this->assertSame(['existing' => 'John', 'incoming' => 'Jonathan'], $differences['firstname']);
        $this->assertSame(['existing' => $this->set2002->uuid, 'incoming' => $this->set1998->uuid], $differences['graduation_set_uuid']);
        $this->assertSame(['existing' => '2002', 'incoming' => '1998'], $differences['graduation_set']);
        $this->assertArrayNotHasKey('lastname', $differences);
        $this->assertArrayNotHasKey('donor_type', $differences);
    }

    public function test_guest_donation_mismatch_logs_guest_and_differences(): void
    {
        $identity = $this->lockedAlumniIdentity('jane@example.com', 'Jane', 'Smith', $this->set2002);

        try {
            app(GivingIdentityResolver::class)->resolveForGuest([
                'donor_email' => 'Jane@Example.com',
                'donor_type_uuid' => $this->friend->uuid,
                'donor_type' => DonorTypeSlug::FRIENDS_OF_ICOBA->value,
                'firstname' => 'Janet',
                'lastname' => 'Smith',
            ]);
            $this->fail('Expected the guest donation to be flagged.');
        } catch (ValidationException) {
            // expected
        }

        $entry = $this->lastLogEntry();

        $this->assertStringStartsWith('GUEST DONATION BLOCKED for jane@example.com | who: guest Janet Smith as Friends of ICOBA | existing: Jane Smith (ICOBA Member Set 2002, identity '.$identity->uuid, $entry['message']);
        $this->assertStringContainsString('| differs on: donor type "icoba_alumni" -> "friends_of_icoba"; first name "Jane" -> "Janet"; graduation set "2002" -> "none" |', $entry['message']);
        $this->assertSame(GivingIdentityFlagLogger::FLOW_GUEST_DONATION, $entry['context']['flow']);
        $this->assertSame(GivingIdentityFlagLogger::OUTCOME_REJECTED_PROFILE_MISMATCH, $entry['context']['outcome']);
        $this->assertArrayNotHasKey('user', $entry['context']);
        $this->assertSame([
            'email' => 'jane@example.com',
            'name' => 'Janet Smith',
            'donor_type' => 'friends_of_icoba',
        ], $entry['context']['guest']);
        $this->assertSame($identity->uuid, $entry['context']['identity']['uuid']);

        $differences = $entry['context']['differences'];
        $this->assertSame(['existing' => 'icoba_alumni', 'incoming' => 'friends_of_icoba'], $differences['donor_type']);
        $this->assertSame(['existing' => 'Jane', 'incoming' => 'Janet'], $differences['firstname']);
        $this->assertSame(['existing' => $this->set2002->uuid, 'incoming' => null], $differences['graduation_set_uuid']);
    }

    public function test_matching_profiles_write_nothing(): void
    {
        $this->lockedAlumniIdentity('same@example.com', 'Ada', 'Okoye', $this->set2002);

        $user = User::factory()->create([
            'email' => 'same@example.com',
            'firstname' => 'ada',
            'lastname' => 'OKOYE',
            'donor_type_uuid' => $this->alumni->uuid,
            'graduation_set_uuid' => $this->set2002->uuid,
        ]);

        app(GivingIdentityResolver::class)->linkRegistrationToIdentity($user);

        $this->assertFileDoesNotExist($this->logPath);
    }

    private function lockedAlumniIdentity(string $email, string $firstname, string $lastname, GraduationSet $set): GivingIdentity
    {
        return GivingIdentity::query()->create([
            'email_lower' => $email,
            'donor_type_uuid' => $this->alumni->uuid,
            'graduation_set_uuid' => $set->uuid,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'status' => GivingIdentityStatus::UNVERIFIED,
            'source' => GivingIdentitySource::GUEST_CHECKOUT,
            'locked_at' => now(),
        ]);
    }

    /**
     * @return array{message: string, context: array<string, mixed>}
     */
    private function lastLogEntry(): array
    {
        $this->assertFileExists($this->logPath);

        $lines = array_values(array_filter(explode("\n", trim((string) file_get_contents($this->logPath)))));
        $line = end($lines);

        $this->assertIsString($line);
        $this->assertSame(1, preg_match('/^\[.+?\] \w+\.(\w+): (.*?) (\{.*\})\s*$/', $line, $matches), 'Unexpected log line: '.$line);

        $context = json_decode($matches[3], true);
        $this->assertIsArray($context, 'Log context is not JSON: '.$matches[3]);

        return ['message' => $matches[2], 'context' => $context];
    }
}
