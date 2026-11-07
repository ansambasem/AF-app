<?php

namespace Tests\Unit;

use App\Support\Messaging\PhoneNumber;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    #[TestWith(['0599123456', '970599123456'])]
    #[TestWith(['059 912-3456', '970599123456'])]
    #[TestWith(['٠٥٩٩١٢٣٤٥٦', '970599123456'])]
    #[TestWith(['+972599123456', '972599123456'])]
    #[TestWith(['00972599123456', '972599123456'])]
    #[TestWith(['970599123456', '970599123456'])]
    #[TestWith(['599123456', '970599123456'])]
    public function test_a_number_is_written_with_its_country_code(string $phone, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::international($phone, '970'));
    }
}
