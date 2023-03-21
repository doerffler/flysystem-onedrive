<?php

namespace Justus\FlysystemOneDrive\Test\Unit;

use Illuminate\Support\Facades\Storage;
use Justus\FlysystemOneDrive\Test\OneDriveTestCase;

class OneDriveTest extends OneDriveTestCase
{
    /**
     * A basic test example.
     */
    public function test_basic_test(): void
    {
        $disk = Storage::build([
            'driver' => 'onedrive',
            'root' => env('ONEDRIVE_ROOT'),
            'directory_type' => env('ONEDRIVE_DIR_TYPE'),
            'access_token' => env('AZURE_ACCESS_TOKEN')
        ]);

        // Test disk operations
    }
}