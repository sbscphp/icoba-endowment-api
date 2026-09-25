<?php

namespace Tests\Unit\Enums;

use App\Enums\DonationPurpose;
use PHPUnit\Framework\TestCase;

final class DonationPurposeTest extends TestCase
{
    public function test_known_slugs_and_labels_normalize_to_slug(): void
    {
        $this->assertSame('general', DonationPurpose::normalize('general'));
        $this->assertSame('student_welfare', DonationPurpose::normalize('Student Welfare'));
        $this->assertSame('student_welfare', DonationPurpose::normalize('student-welfare'));
        $this->assertSame('infrastructure', DonationPurpose::normalize('  INFRASTRUCTURE '));
    }

    public function test_custom_values_are_kept_verbatim_and_blank_is_null(): void
    {
        $this->assertSame('Library renovation', DonationPurpose::normalize('  Library renovation '));
        $this->assertNull(DonationPurpose::normalize(''));
        $this->assertNull(DonationPurpose::normalize('   '));
        $this->assertNull(DonationPurpose::normalize(null));
        $this->assertNull(DonationPurpose::normalize(42));
    }

    public function test_payload_distinguishes_known_and_custom_purposes(): void
    {
        $this->assertSame(
            ['value' => 'student_welfare', 'label' => 'Student Welfare', 'is_custom' => false],
            DonationPurpose::payload('student_welfare'),
        );
        $this->assertSame(
            ['value' => 'Library renovation', 'label' => 'Library renovation', 'is_custom' => true],
            DonationPurpose::payload('Library renovation'),
        );
        $this->assertNull(DonationPurpose::payload(null));
        $this->assertNull(DonationPurpose::payload(''));
    }

    public function test_options_list_the_three_known_purposes_in_order(): void
    {
        $this->assertSame([
            ['value' => 'general', 'label' => 'General'],
            ['value' => 'student_welfare', 'label' => 'Student Welfare'],
            ['value' => 'infrastructure', 'label' => 'Infrastructure'],
        ], DonationPurpose::options());
    }
}
