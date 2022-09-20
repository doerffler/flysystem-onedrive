# Flysystem adapter for Microsoft OneDrive
This package contains a Flysystem OneDrive adapter, which is operated with the Microsoft Graph API.
The adapter can also be used with the latest Laravel 9.x version.

## 1. Installation
You can install the package via composer:

`composer require justus/flysystem-onedrive`

## 2. Usage
1. Add the following variable to the ``.env`` file

```dotenv
ONEDRIVE_ROOT=root/path
ONEDRIVE_ACCESS_TOKEN=fd6s7a98...
```

2. In the file ``config/filesystems.php``, please add the following code snippet in the disks section

```php
onedrive' => [
    'driver' => 'onedrive',
    'root' => env('ONEDRIVE_ROOT'),
    'access_token' => env('ONEDRIVE_ACCESS_TOKEN') //optional
],
```

3. Add the ``OneDriveAdapterServiceProvider`` in ``config/app.php``

```php
'providers' => [
    // ...
    Justus\FlysystemOneDrive\Providers\OneDriveAdapterServiceProvider::class,
    // ...
],
```

4. Using with the Storage Facade

On-Demand usage
```php
$disk = Storage::build([
    'driver' => config('filesystems.disks.onedrive.driver'),
    'root' => config('filesystems.disks.onedrive.root'),
    'use_path' => true,
    'access_token' => '<access_token>'
]);

$disk->makeDirectory('test');
```

## 3. Changelog
Please see CHANGELOG for more information what has changed recently.

## 4. Testing
`$ composer test`

## 5. Security
If you discover any security related issues, please email jdonner@doerffler.com instead of using the issue tracker.

## 6. License
The MIT License (MIT). Please see License File for more information.
