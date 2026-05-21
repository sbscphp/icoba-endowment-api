<?php

namespace Tests\Unit\Services\Phone;

use App\Models\Country;
use App\Services\Phone\PhoneNumberService;
use PHPUnit\Framework\TestCase;

final class PhoneNumberServiceTest extends TestCase
{
    private PhoneNumberService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PhoneNumberService;
    }

    public function test_normalizes_nigerian_local_mobile(): void
    {
        $country = $this->country('NG', '+234');

        $result = $this->service->normalize('08031234567', $country);

        $this->assertNotNull($result);
        $this->assertSame('+2348031234567', $result['phone_number']);
        $this->assertSame('+234', $result['country_code']);
    }

    public function test_rejects_invalid_nigerian_local_mobile(): void
    {
        $country = $this->country('NG', '+234');

        $this->assertNull($this->service->normalize('0809478432', $country));
    }

    public function test_normalizes_uk_local_mobile(): void
    {
        $country = $this->country('GB', '+44');

        $result = $this->service->normalize('07123456789', $country);

        $this->assertNotNull($result);
        $this->assertSame('+447123456789', $result['phone_number']);
        $this->assertSame('+44', $result['country_code']);
    }

    public function test_normalizes_us_national_number(): void
    {
        $country = $this->country('US', '+1');

        $result = $this->service->normalize('4155552671', $country);

        $this->assertNotNull($result);
        $this->assertSame('+14155552671', $result['phone_number']);
        $this->assertSame('+1', $result['country_code']);
    }

    public function test_rejects_number_for_wrong_country(): void
    {
        $country = $this->country('NG', '+234');

        $this->assertNull($this->service->normalize('+447123456789', $country));
    }

    public function test_rejects_double_country_prefix_input(): void
    {
        $country = $this->country('NG', '+234');

        $this->assertNull($this->service->normalize('234809478432', $country));
    }

    public function test_to_sms_digits_strips_plus(): void
    {
        $this->assertSame('234809478432', $this->service->toSmsDigits('+234809478432'));
    }

    public function test_equivalent_stored_values_includes_e164_local_and_digits(): void
    {
        $forms = $this->service->equivalentStoredValues('+2348012345681');

        $this->assertContains('+2348012345681', $forms);
        $this->assertContains('2348012345681', $forms);
        $this->assertContains('08012345681', $forms);
    }

    private function country(string $iso2, string $dialCode): Country
    {
        $country = new Country;
        $country->iso2 = $iso2;
        $country->dial_code = $dialCode;

        return $country;
    }
}
