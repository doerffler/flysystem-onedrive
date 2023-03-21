<?php

namespace Justus\FlysystemOneDrive\Test;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Justus\FlysystemOneDrive\Providers\OneDriveAdapterServiceProvider;
use Orchestra\Testbench\TestCase;

class OneDriveTestCase extends TestCase
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Get package providers.
     *
     * @param  Application  $app
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            OneDriveAdapterServiceProvider::class,
        ];
    }

    /**
     * Ignore package discovery from.
     *
     * @return array<int, string>
     */
    public function ignorePackageDiscoveriesFrom(): array
    {
        return [];
    }

    /**
     * Automatically enables package discoveries.
     *
     * @var bool
     */
    protected $enablesPackageDiscoveries = true;
}