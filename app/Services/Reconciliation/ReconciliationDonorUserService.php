<?php

namespace App\Services\Reconciliation;

use App\Enums\DonorTypeSlug;
use App\Enums\eRole;
use App\Jobs\LinkGuestDonorHistoryJob;
use App\Models\Country;
use App\Models\DonorType;
use App\Models\GraduationSet;
use App\Models\User;
use App\Repositories\Contracts\User\UserRepositoryInterface;
use App\Services\GivingIdentity\GivingIdentityResolver;
use App\Services\Phone\PhoneNumberService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReconciliationDonorUserService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly PhoneNumberService $phoneNumberService,
        private readonly GivingIdentityResolver $givingIdentityResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFromProfile(array $data): User
    {
        $email = strtolower(trim((string) ($data['donor_email'] ?? '')));
        if ($email === '') {
            throw ValidationException::withMessages([
                'donor_email' => ['Donor email is required when creating a new user.'],
            ]);
        }

        if ($this->userRepository->findByEmail($email) !== null) {
            throw ValidationException::withMessages([
                'donor_email' => ['A donor with this email already exists. Please choose an existing donor instead.'],
            ]);
        }

        $phoneNumber = trim((string) ($data['donor_phone'] ?? ''));
        if ($phoneNumber !== '' && $this->phoneNumberService->isRegistered($phoneNumber)) {
            throw ValidationException::withMessages([
                'donor_phone' => ['A donor with this phone number already exists. Please choose an existing donor instead.'],
            ]);
        }

        $donorType = $this->resolveDonorType($data);
        if ($donorType === null) {
            throw ValidationException::withMessages([
                'donor_type' => ['Donor type is required when creating a new user.'],
            ]);
        }

        $user = $this->userRepository->create(
            $this->mapProfileToUserPayload($data, $donorType, $email, $phoneNumber),
        );
        $user->assignRole(eRole::CUSTOMER->value);

        $this->givingIdentityResolver->linkRegistrationToIdentity($user);

        LinkGuestDonorHistoryJob::dispatch($user->uuid);

        return $user->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveDonorType(array $data): ?DonorType
    {
        if (isset($data['donor_type_uuid']) && is_string($data['donor_type_uuid']) && $data['donor_type_uuid'] !== '') {
            return DonorType::query()->where('uuid', $data['donor_type_uuid'])->first();
        }

        $slug = isset($data['donor_type']) ? strtolower(trim((string) $data['donor_type'])) : '';
        if ($slug === '') {
            return null;
        }

        return DonorType::query()->where('slug', $slug)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapProfileToUserPayload(array $data, DonorType $donorType, string $email, string $phoneNumber): array
    {
        $row = [
            'email' => $email,
            'password' => Hash::make(Str::password(32)),
            'phone_number' => $phoneNumber !== '' ? $phoneNumber : null,
            'country_code' => $data['country_code'] ?? Country::defaultDialCode(),
            'donor_type_uuid' => $donorType->uuid,
            'corporate_category_uuid' => null,
            'graduation_set_uuid' => null,
            'organization_name' => null,
            'rc_number' => null,
            'tin' => null,
            'alumni_identifier' => null,
            'email_verified_at' => now(),
            'is_active' => true,
            'can_login' => false,
        ];

        return match ($donorType->slug) {
            DonorTypeSlug::ICOBA_ALUMNI->value => array_merge($row, [
                'firstname' => trim((string) ($data['firstname'] ?? '')),
                'lastname' => trim((string) ($data['lastname'] ?? '')),
                'graduation_set_uuid' => GraduationSet::query()
                    ->where('set_number', (string) ($data['set_number'] ?? ''))
                    ->value('uuid'),
                'alumni_identifier' => filled($data['alumni_identifier'] ?? null)
                    ? (string) $data['alumni_identifier']
                    : null,
            ]),
            DonorTypeSlug::CORPORATE_DONOR->value => array_merge($row, [
                'organization_name' => trim((string) ($data['organization_name'] ?? '')),
                'corporate_category_uuid' => $data['corporate_category_uuid'] ?? null,
                'rc_number' => trim((string) ($data['rc_number'] ?? '')),
                'tin' => trim((string) ($data['tin'] ?? '')),
                'firstname' => trim((string) ($data['organization_name'] ?? '')),
                'lastname' => '',
            ]),
            DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            DonorTypeSlug::RELATIVES_OF_ICOBA->value => array_merge($row, [
                'firstname' => trim((string) ($data['firstname'] ?? '')),
                'lastname' => trim((string) ($data['lastname'] ?? '')),
            ]),
            default => array_merge($row, [
                'firstname' => trim((string) ($data['firstname'] ?? 'Donor')),
                'lastname' => trim((string) ($data['lastname'] ?? '')),
            ]),
        };
    }
}
