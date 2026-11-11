<?php

namespace Tests\Unit;

use App\Models\Product;
use PHPUnit\Framework\TestCase;

class BarcodeVariantsTest extends TestCase
{
    public function test_a_12_digit_upc_also_matches_its_13_digit_form(): void
    {
        $this->assertSame(['012345678905', '0012345678905'], Product::barcodeVariants('012345678905'));
    }

    public function test_a_13_digit_code_with_a_leading_zero_also_matches_its_upc_form(): void
    {
        $this->assertSame(['0012345678905', '012345678905'], Product::barcodeVariants('0012345678905'));
    }

    public function test_other_codes_only_match_themselves(): void
    {
        $this->assertSame(['4800016644290'], Product::barcodeVariants('4800016644290'));
        $this->assertSame(['96385074'], Product::barcodeVariants('96385074'));
        $this->assertSame(['ABC123456789'], Product::barcodeVariants('ABC123456789'));
    }
}
