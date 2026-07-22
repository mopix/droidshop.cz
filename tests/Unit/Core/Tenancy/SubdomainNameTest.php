<?php

namespace Tests\Unit\Core\Tenancy;

use App\Core\Tenancy\Exceptions\InvalidSubdomain;
use App\Core\Tenancy\SubdomainName;
use Tests\TestCase;

class SubdomainNameTest extends TestCase
{
    public function test_normalises_case_and_trim(): void
    {
        $this->assertSame('mujshop', SubdomainName::normalise('  MujShop '));
    }

    public function test_accepts_valid_slug(): void
    {
        $this->assertTrue(SubdomainName::isValidFormat('muj-shop-1'));
    }

    public function test_rejects_bad_format(): void
    {
        $this->assertFalse(SubdomainName::isValidFormat('-x'));       // leading dash
        $this->assertFalse(SubdomainName::isValidFormat('ab'));        // too short (<3)
        $this->assertFalse(SubdomainName::isValidFormat('a_b'));       // underscore
        $this->assertFalse(SubdomainName::isValidFormat(str_repeat('a', 64))); // too long
    }

    public function test_reserved_detected(): void
    {
        $this->assertTrue(SubdomainName::isReserved('www'));
        $this->assertFalse(SubdomainName::isReserved('mujshop'));
    }

    public function test_from_input_throws_on_reserved(): void
    {
        $this->expectException(InvalidSubdomain::class);
        SubdomainName::fromInput('admin');
    }

    public function test_host_appends_platform_domain(): void
    {
        config()->set('tenancy.platform_domain', 'droidshop.cz');
        $this->assertSame('mujshop.droidshop.cz', SubdomainName::host('mujshop'));
    }
}
