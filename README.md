![![Banner]](https://banners.beyondco.de/Flysystem%20OneDrive.png?theme=light&packageManager=composer+require&packageName=justus%2Fflysystem-onedrive&pattern=architect&style=style_1&description=A+flysystem+driver+for+OneDrive+that+uses+the+Microsoft+Graph+API&md=1&showWatermark=0&fontSize=100px&images=cloud)

# Flysystem adapter for Microsoft OneDrive
This package contains a Flysystem OneDrive adapter, which makes use of the Microsoft Graph API.
The adapter is ready for the latest Laravel 9.x version.

[![Latest Stable Version](http://poser.pugx.org/justus/flysystem-onedrive/v)](https://packagist.org/packages/justus/flysystem-onedrive) [![Total Downloads](http://poser.pugx.org/justus/flysystem-onedrive/downloads)](https://packagist.org/packages/justus/flysystem-onedrive) [![Latest Unstable Version](http://poser.pugx.org/justus/flysystem-onedrive/v/unstable)](https://packagist.org/packages/justus/flysystem-onedrive) [![License](http://poser.pugx.org/justus/flysystem-onedrive/license)](https://packagist.org/packages/justus/flysystem-onedrive) [![PHP Version Require](http://poser.pugx.org/justus/flysystem-onedrive/require/php)](https://packagist.org/packages/justus/flysystem-onedrive)

## 1. Installation
Just install the package using composer:

`composer require justus/flysystem-onedrive`

## 2. Usage

### Laravel Usage
1. Add the following variables to your ``.env`` file

```dotenv
ONEDRIVE_ROOT=root/path
ONEDRIVE_ACCESS_TOKEN=fd6s7a98...
```

2. In the file ``config/filesystems.php``, please add the following code snippet to the disks section

```php
'onedrive' => [
    'driver' => 'onedrive',
    'root' => env('ONEDRIVE_ROOT'),
    'access_token' => env('ONEDRIVE_ACCESS_TOKEN') //optional if demanded
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

There are two established approaches of using the package
- On demand: Recommended if you use a dynamic graph access token. (usage e. g. session('graph_access_token'))
```php
$disk = Storage::build([
    'driver' => config('filesystems.disks.onedrive.driver'),
    'root' => config('filesystems.disks.onedrive.root'),
    'use_path' => true,
    'access_token' => session('graph_access_token')
]);

$disk->makeDirectory('test');
```
- Default with Storage Facade: Recommended if you use a static graph access token.
```php
Storage::disk('onedrive')->makeDirectory('test');
```
### PHP Usage
Usage in php without Laravel framework
```php
$graph = new Graph();
$graph->setAccessToken('fd6s7a98...');

$adapter = new OneDriveAdapter($graph, 'root/path', true);

$filesystem = new Filesystem($adapter);

$filesystem->createDirectory('test');
```

## 3. Changelog
Please see CHANGELOG for more information on recent changes.

## 4. Testing
`$ composer test`

## 5. Security
If you discover any security related issues, please write an email to jdonner@doerffler.com instead of using the issue tracker.

## 6. License
The MIT License (MIT). Please see License File for more information.
