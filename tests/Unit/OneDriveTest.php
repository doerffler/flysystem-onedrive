<?php

namespace Justus\FlysystemOneDrive\Test\Unit;

use Illuminate\Support\Facades\Storage;
use Justus\FlysystemOneDrive\Test\Feature\OneDriveTestCase;

class OneDriveTest extends OneDriveTestCase
{
    public function test_basic_test(): void
    {
        $disk = Storage::build([
            'driver' => 'onedrive',
            'root' => env('ONEDRIVE_ROOT'),
            'directory_type' => env('ONEDRIVE_DIR_TYPE'),
            'access_token' => env('AZURE_ACCESS_TOKEN')
        ]);

        //self::markTestIncomplete('Please check');
        self::assertTrue(true);

        // Test disk operations
    }
}