<?php

namespace Tests\Feature\Modules;

use App\Core\Modules\DependencyResolver;
use App\Core\Modules\Exceptions\UnresolvableDependencies;
use App\Core\Modules\Manifest;
use Tests\TestCase;

class DependencyResolverTest extends TestCase
{
    private DependencyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(DependencyResolver::class);
    }

    /**
     * @param  array<string, string>  $requires
     */
    private function module(string $name, array $requires = [], string $version = '1.0.0'): Manifest
    {
        return Manifest::fromArray([
            'name' => $name,
            'version' => $version,
            'requires' => $requires,
        ]);
    }

    public function test_dependencies_come_before_dependents(): void
    {
        $sorted = $this->resolver->sort([
            'checkout' => $this->module('checkout', ['products' => '^1.0']),
            'products' => $this->module('products'),
        ]);

        $this->assertLessThan(
            array_search('checkout', $sorted, true),
            array_search('products', $sorted, true),
            'products must boot before checkout.'
        );
    }

    public function test_transitive_dependencies_are_ordered(): void
    {
        $sorted = $this->resolver->sort([
            'orders' => $this->module('orders', ['checkout' => '^1.0']),
            'checkout' => $this->module('checkout', ['products' => '^1.0']),
            'products' => $this->module('products'),
        ]);

        $this->assertSame(['products', 'checkout', 'orders'], $sorted);
    }

    public function test_order_is_deterministic_for_independent_modules(): void
    {
        // An order that shuffles between deploys makes route registration and
        // navigation non-reproducible, and those bugs are miserable to chase.
        $first = $this->resolver->sort([
            'zeta' => $this->module('zeta'),
            'alpha' => $this->module('alpha'),
            'mid' => $this->module('mid'),
        ]);

        $second = $this->resolver->sort([
            'mid' => $this->module('mid'),
            'zeta' => $this->module('zeta'),
            'alpha' => $this->module('alpha'),
        ]);

        $this->assertSame($first, $second);
    }

    public function test_cycle_is_rejected(): void
    {
        $this->expectException(UnresolvableDependencies::class);
        $this->expectExceptionMessageMatches('/cycle/');

        $this->resolver->sort([
            'a' => $this->module('a', ['b' => '^1.0']),
            'b' => $this->module('b', ['a' => '^1.0']),
        ]);
    }

    public function test_self_dependency_is_a_cycle(): void
    {
        $this->expectException(UnresolvableDependencies::class);

        $this->resolver->sort(['a' => $this->module('a', ['a' => '^1.0'])]);
    }

    public function test_missing_dependency_is_reported(): void
    {
        $problems = $this->resolver->unmetDependencies(
            $this->module('checkout', ['products' => '^1.0']),
            []
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('not installed', $problems[0]);
    }

    public function test_version_mismatch_is_reported(): void
    {
        $problems = $this->resolver->unmetDependencies(
            $this->module('checkout', ['products' => '^2.0']),
            ['products' => $this->module('products', [], '1.5.0')]
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('but version 1.5.0 is installed', $problems[0]);
    }

    public function test_satisfied_dependency_reports_nothing(): void
    {
        $problems = $this->resolver->unmetDependencies(
            $this->module('checkout', ['products' => '^1.0']),
            ['products' => $this->module('products', [], '1.5.0')]
        );

        $this->assertSame([], $problems);
    }
}
