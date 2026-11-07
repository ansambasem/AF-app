<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pages render without compiled assets or a running SSR service, so the
     * tests pass on a fresh checkout and in CI, which only installs the
     * PHP dependencies.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['inertia.ssr.enabled' => false]);
    }
}
