<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Any 404 now renders the shop template, which pulls in the storefront
        // bundle — so a test suite would otherwise depend on `npm run build`
        // having been run. Tests assert markup, never asset hashes.
        $this->withoutVite();
    }
}
