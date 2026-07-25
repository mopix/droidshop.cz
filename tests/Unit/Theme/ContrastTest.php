<?php

namespace Tests\Unit\Theme;

use App\Core\Theme\Contrast;
use PHPUnit\Framework\TestCase;

class ContrastTest extends TestCase
{
    public function test_text_on_dark_background_is_white(): void
    {
        $this->assertSame('#ffffff', Contrast::textOn('#0f172a'));
    }

    public function test_text_on_light_background_is_dark(): void
    {
        $this->assertSame('#0f172a', Contrast::textOn('#fde047'));
    }

    public function test_ratio_between_black_and_white_is_max(): void
    {
        $this->assertEqualsWithDelta(21.0, Contrast::ratio('#000000', '#ffffff'), 0.1);
    }

    public function test_ratio_between_mid_gray_and_white_is_low(): void
    {
        $this->assertLessThan(4.5, Contrast::ratio('#777777', '#ffffff'));
    }

    public function test_ratio_between_identical_colors_is_one(): void
    {
        $this->assertSame(1.0, Contrast::ratio('#ffffff', '#ffffff'));
    }
}
