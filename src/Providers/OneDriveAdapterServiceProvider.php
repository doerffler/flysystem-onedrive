<?php

namespace Justus\FlysystemOneDrive\Providers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Justus\FlysystemOneDrive\OneDriveAdapter;
use League\Flysystem\Filesystem;
use Microsoft\Graph\Graph;

class OneDriveAdapterServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        Storage::extend('onedrive', function ($app, $config) {
            $options = [
                'directory_type' => $config['directory_type'],
            ];

            $graph = new Graph();
            $graph->setAccessToken($config['access_token']);
            $adapter = new OneDriveAdapter($graph, $config['root'], $options);

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }
}