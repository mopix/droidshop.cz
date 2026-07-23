<?php

namespace Tests\Unit\Domains;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDnsChecker;

/**
 * FakeDnsChecker is the deterministic double domain-verification tests rely
 * on — this just pins its contract: configured answers come back as set,
 * unconfigured hosts answer empty/null, and host matching is case-insensitive
 * (DNS itself is case-insensitive, and Domain stores hosts lowercase).
 */
class FakeDnsCheckerTest extends TestCase
{
    public function test_returns_configured_txt_values(): void
    {
        $dns = new FakeDnsChecker;
        $dns->setTxt('shop.example.com', ['droidshop-verify=abc123']);

        $this->assertSame(['droidshop-verify=abc123'], $dns->txt('shop.example.com'));
    }

    public function test_returns_configured_cname_target(): void
    {
        $dns = new FakeDnsChecker;
        $dns->setCname('shop.example.com', 'edge.droidshop.cz');

        $this->assertSame('edge.droidshop.cz', $dns->cname('shop.example.com'));
    }

    public function test_returns_configured_a_records(): void
    {
        $dns = new FakeDnsChecker;
        $dns->setA('shop.example.com', ['203.0.113.10']);

        $this->assertSame(['203.0.113.10'], $dns->a('shop.example.com'));
    }

    public function test_unconfigured_host_answers_empty_or_null(): void
    {
        $dns = new FakeDnsChecker;

        $this->assertSame([], $dns->txt('unset.example.com'));
        $this->assertNull($dns->cname('unset.example.com'));
        $this->assertSame([], $dns->a('unset.example.com'));
    }

    public function test_host_lookup_is_case_insensitive(): void
    {
        $dns = new FakeDnsChecker;
        $dns->setTxt('Shop.Example.com', ['droidshop-verify=abc123']);
        $dns->setCname('Shop.Example.com', 'Edge.Droidshop.cz');
        $dns->setA('Shop.Example.com', ['203.0.113.10']);

        $this->assertSame(['droidshop-verify=abc123'], $dns->txt('shop.example.com'));
        $this->assertSame('Edge.Droidshop.cz', $dns->cname('SHOP.EXAMPLE.COM'));
        $this->assertSame(['203.0.113.10'], $dns->a('shop.EXAMPLE.com'));
    }
}
