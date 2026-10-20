<?php

namespace Justus\FlysystemOneDrive\Providers;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Justus\FlysystemOneDrive\OneDriveAdapter;
use League\Flysystem\Filesystem;
use Microsoft\Graph\Graph;
use RuntimeException;

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

            $resolveAccessToken = fn (): string => OneDriveAdapterServiceProvider::resolveToken($config);

            // A queue worker keeps this disk for hours, past the token's lifetime,
            // so every request asks for the (cached) current token instead.
            $graph = new class($resolveAccessToken) extends Graph
            {
                public function __construct(private readonly Closure $resolveAccessToken)
                {
                    parent::__construct();
                }

                public function createRequest($requestType, $endpoint)
                {
                    $this->setAccessToken(($this->resolveAccessToken)());

                    return parent::createRequest($requestType, $endpoint);
                }

                public function createCollectionRequest($requestType, $endpoint)
                {
                    $this->setAccessToken(($this->resolveAccessToken)());

                    return parent::createCollectionRequest($requestType, $endpoint);
                }
            };

            $graph->setAccessToken($resolveAccessToken());
            $adapter = new OneDriveAdapter($graph, $config['root'], $options);

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function resolveToken(array $config): string
    {
        $accessToken = $config['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            // 1. Read config. Do not cast directly; first ensure it's actually a string.
            $tenantId = $config['tenant_id'] ?? config('filesystems.disks.onedrive.tenant_id');
            $clientId = $config['client_id'] ?? config('filesystems.disks.onedrive.client_id');
            $clientSecret = $config['secret'] ?? config('filesystems.disks.onedrive.secret');

            if (
                ! is_string($tenantId) ||
                ! is_string($clientId) ||
                ! is_string($clientSecret)
            ) {
                throw new RuntimeException(
                    'Invalid or missing OneDrive configuration values. '.
                        'Check tenant_id, client_id, and secret in config/filesystems.php.'
                );
            }
            $cacheKey = 'onedrive_token_'.hash('sha256', $tenantId.'|'.$clientId.'|'.$clientSecret);
            $cachedToken = Cache::get($cacheKey);
            if ($cachedToken && isset($cachedToken['token'], $cachedToken['expires_at'])) {
                // Leave a minute so a request does not set off with a token about to expire
                if (time() < $cachedToken['expires_at'] - 60) {
                    $accessToken = $cachedToken['token'];
                }
            }

            // If $accessToken is still not set, we need to request a new one
            if (! is_string($accessToken) || $accessToken === '') {
                $oauthUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

                $response = Http::acceptJson()->asForm()->post($oauthUrl, [
                    'client_id' => $clientId,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                    'client_secret' => $clientSecret,
                ]);

                $json = $response->json();
                if (! is_array($json) || ! isset($json['access_token'], $json['expires_in'])) {
                    throw new RuntimeException('Invalid OneDrive OAuth response.');
                }

                $accessToken = $json['access_token'];
                $expiresIn = $json['expires_in']; // seconds until expiry
                $expiresAt = time() + $expiresIn;

                // Cache the token + expiry time
                // (store a little shorter if you want a buffer, e.g. $expiresIn - 30)
                Cache::put($cacheKey, [
                    'token' => $accessToken,
                    'expires_at' => $expiresAt,
                ], $expiresIn);
            }

        }

        return $accessToken;
    }
}
