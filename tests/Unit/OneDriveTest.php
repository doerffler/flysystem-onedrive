<?php

namespace Justus\FlysystemOneDrive\Test\Unit;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Justus\FlysystemOneDrive\OneDriveAdapter;
use Justus\FlysystemOneDrive\Test\Feature\OneDriveTestCase;

class OneDriveTest extends OneDriveTestCase
{
    public function test_builds_a_disk_with_an_explicit_access_token(): void
    {
        Http::preventStrayRequests();

        $disk = Storage::build([
            'driver' => 'onedrive',
            'root' => 'test-drive',
            'directory_type' => 'drives',
            'access_token' => 'test-token',
        ]);

        self::assertInstanceOf(FilesystemAdapter::class, $disk);
        self::assertInstanceOf(OneDriveAdapter::class, $disk->getAdapter());
        self::assertSame('/drives/test-drive/root', $disk->getAdapter()->getDriveRootUrl());
        Http::assertNothingSent();
    }

    public function test_authenticates_and_reuses_the_token_for_the_same_credentials(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
        ]);

        $config = [
            'driver' => 'onedrive',
            'root' => 'test-drive',
            'directory_type' => 'drives',
            'tenant_id' => 'tenant-one',
            'client_id' => 'client-one',
            'secret' => 'test-secret',
        ];

        Storage::build($config);
        Storage::build($config);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['client_id'] === 'client-one'
            && $request['grant_type'] === 'client_credentials');

        Storage::build(array_replace($config, ['client_id' => 'client-two']));
        Http::assertSentCount(2);
    }

    public function test_missing_credentials_are_rejected(): void
    {
        Http::preventStrayRequests();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid or missing OneDrive configuration');

        Storage::build([
            'driver' => 'onedrive',
            'root' => 'test-drive',
            'directory_type' => 'drives',
        ]);
    }

    public function test_refreshes_an_expired_token_on_a_long_lived_disk(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*' => Http::sequence()
                ->push(['access_token' => 'first-token', 'expires_in' => 3600])
                ->push(['access_token' => 'second-token', 'expires_in' => 3600]),
        ]);

        $disk = Storage::build([
            'driver' => 'onedrive',
            'root' => 'test-drive',
            'directory_type' => 'drives',
            'tenant_id' => 'tenant-one',
            'client_id' => 'client-one',
            'secret' => 'test-secret',
        ]);
        $graph = (fn () => $this->graph)->call($disk->getAdapter());

        $this->travel(2)->hours();
        $request = $graph->createRequest('GET', '/me');

        Http::assertSentCount(2);
        self::assertSame('Bearer second-token', $request->getHeaders()['Authorization']);
    }
}
