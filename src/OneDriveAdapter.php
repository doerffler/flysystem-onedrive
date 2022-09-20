<?php

namespace Justus\FlysystemOneDrive;

use ArrayObject;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use Microsoft\Graph\Graph;

class OneDriveAdapter extends OneDriveUtilityAdapter implements FilesystemAdapter
{
    protected Graph $graph;
    private bool $usePath;

    public function __construct(Graph $graph, string $prefix = 'root', bool $usePath = true)
    {
        $this->graph = $graph;
        $this->usePath = $usePath;

        $this->setPathPrefix('/'.$prefix.($this->usePath ? ':' : ''));
    }

    /**
     * @param string $path
     * @return bool
     * @throws Exception
     */
    public function fileExists(string $path): bool
    {
        try {
            if ($this->readStream($path))
                return true;
            else
                return false;
        }
        catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param string $path
     * @return bool
     * @throws Exception
     */
    public function directoryExists(string $path): bool
    {
        try {
            if ($this->readStream($path))
                return true;
            else
                return false;
        }
        catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config|null $config
     * @return void
     */
    public function write(string $path, string $contents, Config $config = null): void
    {
        $this->upload($path, $contents);
    }

    /**
     * @param string $path
     * @param $contents
     * @param Config|null $config
     * @return void
     */
    public function writeStream(string $path, $contents, Config $config = null): void
    {
        $this->upload($path, $contents);
    }

    /**
     * @param string $path
     * @return string
     */
    public function read(string $path): string
    {
        if (! $object = $this->readStream($path)) {
            return false;
        }

        $object['contents'] = stream_get_contents($object['stream']);

        fclose($object['stream']);
        unset($object['stream']);

        return $object['contents'];
    }

    /**
     * @param string $path
     * @return array
     * @throws GuzzleException
     */
    public function readStream(string $path): array
    {
        $path = $this->applyPathPrefix($path);

        try {
            $file = tempnam(sys_get_temp_dir(), 'onedrive');

            $this->graph->createRequest('GET', $path)
                ->download($file);


            $stream = fopen($file, 'r');

            return compact('stream');
        } catch (Exception $e) {

        }
        return array();
    }

    /**
     * @param string $path
     * @return void
     * @throws GuzzleException
     */
    public function delete(string $path): void
    {
        $endpoint = $this->applyPathPrefix($path);

        try {
            $this->graph->createRequest('DELETE', $endpoint)->execute();
        } catch (Exception $e) {

        }
    }

    /**
     * @param string $path
     * @return void
     * @throws GuzzleException
     */
    public function deleteDirectory(string $path): void
    {
        $this->delete($path);
    }

    /**
     * @param string $path
     * @param Config|null $config
     * @return void
     * @throws GuzzleException
     */
    public function createDirectory(string $path, Config $config = null): void
    {
        $patch = explode('/', $path);
        $sliced = implode('/', array_slice($patch, 0, -1));


        if (empty($sliced) && $this->usePath) {
            $endpoint = str_replace(':/', '', $this->getPathPrefix()).'/children';
        } else {
            $endpoint = $this->applyPathPrefix($sliced).($this->usePath ? ':' : '').'/children';
        }


        try {
            $this->graph->createRequest('POST', $endpoint)
                ->attachBody([
                    'name' => end($patch),
                    'folder' => new ArrayObject(),
                ])->execute();
        } catch (Exception $e) {

        }
    }

    /**
     * @param string $path
     * @param string $visibility
     * @return void
     * @throws Exception
     */
    public function setVisibility(string $path, string $visibility): void
    {

    }

    /**
     * @param string $path
     * @return FileAttributes
     * @throws Exception
     */
    public function visibility(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * @param string $path
     * @return FileAttributes
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * @param string $path
     * @return FileAttributes
     * @throws Exception
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * @param string $path
     * @return FileAttributes
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }


    /**
     * @param string $path
     * @param bool $deep
     * @return iterable
     */

    public function listContents(string $path, bool $deep = true): iterable
    {
        if ($path === '' && $this->usePath) {
            $endpoint = str_replace(':/', '', $this->getPathPrefix()).'/children';
        } else {
            $endpoint = $this->applyPathPrefix($path).($this->usePath ? ':' : '').'/children';
        }


        try {
            $response = $this->graph->createRequest('GET', $endpoint)->execute();
            $items = $response->getBody()['value'];
            if (! count($items)) {
                return [];
            }

            foreach ($items as $item) {
                $itemName = $path.'/'.$item['name'];
                yield $this->getMetadata($itemName);
                if ($deep && isset($item['folder'])) {
                    yield from $this->listContents($itemName);
                }
            }

        } catch (Exception $e) {

        }
    }

    /**
     * @param string $source
     * @param string $destination
     * @param Config|null $config
     * @return void
     * @throws Exception
     */
    public function move(string $source, string $destination, Config $config = null): void
    {
        try {
            $this->copy($source, $destination);
            $this->delete($source);
        } catch (Exception $e) {

        }
    }

    /**
     * @param string $source
     * @param string $destination
     * @param Config|null $config
     * @return void
     */
    public function copy(string $source, string $destination, Config $config = null): void
    {
        $endpoint = $this->applyPathPrefix($source);

        $patch = explode('/', $destination);
        $sliced = implode('/', array_slice($patch, 0, -1));

        if (empty($sliced) && $this->usePath) {
            $sliced = $destination;
        }

        try {
            $promise = $this->graph->createRequest('POST', $endpoint.($this->usePath ? ':' : '').'/copy')
                ->attachBody([
                    'name' => $source,
                    'parentReference' => [
                        'path' => $this->getPathPrefix().(empty($sliced) ? '' : rtrim($sliced, '/').'/'),
                    ],
                ])
                ->executeAsync();
            $promise->wait();
        } catch (Exception $e) {

        }
    }

    protected function upload(string $path, $contents): bool
    {
        $path = $this->applyPathPrefix($path);

        try {
            $result = Utils::streamFor($contents);

            $this->graph->createRequest('PUT', $path.($this->usePath ? ':' : '').'/content')
                ->attachBody($result)
                ->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    protected function normalizeResponse(array $response, string $path): array
    {
        $path = trim($this->removePathPrefix($path), '/');

        return [
            'path' => empty($path) ? $response['name'] : $path.'/'.$response['name'],
            'timestamp' => strtotime($response['lastModifiedDateTime']),
            'size' => $response['size'],
            'bytes' => $response['size'],
            'type' => isset($response['file']) ? 'file' : 'dir',
            'mimetype' => isset($response['file']) ? $response['file']['mimeType'] : null,
            'link' => $response['webUrl'] ?? null,
        ];
    }

    public function getMetadata($path): StorageAttributes
    {
        try {
            $path = $this->applyPathPrefix($path);
            $response = $this->graph->createRequest('GET', $path)->execute();
            $result = $response->getBody();

            if (!empty($result['file'])) {
                return new FileAttributes(
                    $path,
                    $result['size'],
                    'unsupported',
                    strtotime($result['lastModifiedDateTime']),
                    $result['file']['mimeType'],
                    $result);
            }

            if (!empty($result['folder'])) {
                return new DirectoryAttributes(
                    $path,
                    'unsupported',
                    strtotime($result['lastModifiedDateTime']),
                    $result);
            }

        } catch (Exception $e) {

        }

        return new FileAttributes('');
    }
}
