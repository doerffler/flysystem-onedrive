<?php

namespace Justus\FlysystemOneDrive;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use Microsoft\Graph\Exception\GraphException;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Http\GraphResponse;
use Microsoft\Graph\Model\Directory;
use Microsoft\Graph\Model\DriveItem;
use Microsoft\Graph\Model\File;
use Microsoft\Graph\Model\UploadSession;
use stdClass;

class OneDriveAdapter extends OneDriveUtilityAdapter implements FilesystemAdapter
{
    protected Graph $graph;
    protected string $drive;
    protected array $options = [];

    /**
     * @throws Exception
     */
    public function __construct(Graph $graph, string $drive, array $options = [])
    {
        $this->graph = $graph;
        $this->drive = $drive;

        $default_options = [
            'request_timeout' => 90,
            'chunk_size' => 320 * 1024 * 10,
        ];

        $this->options = array_merge($default_options, $options);

        if ($this->options['chunk_size'] % (320 * 1024)) {
            throw new Exception('Chunk size must be a multiple of 320KB');
        }
    }

    public function getDriveRootUrl(): string
    {
        return '/' . $this->options['directory_type'] . '/' . $this->drive . '/root';
    }

    public function getUrlToPath(string $path): string
    {
        if ($path === '' || $path === '.' || $path === '/') {
            return $this->getDriveRootUrl();
        }

        return $this->getDriveRootUrl() . ':/' . $path;
    }

    /**
     * @throws GraphException
     * @throws GuzzleException
     */
    protected function getDriveItemUrl(string $path): string
    {
        return '/' . $this->options['directory_type'] . '/' . $this->drive . '/items/' . $this->getDriveItem($path)->getId();
    }

    /**
     * @param string $path
     * @return bool
     * @throws Exception|GuzzleException
     */
    public function fileExists(string $path): bool
    {
        $path = $this->getUrlToPath($path);
        try {
            $this->getFile($path);

            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * @param string $path
     * @return bool
     * @throws Exception|GuzzleException
     */
    public function directoryExists(string $path): bool
    {
        $path = $this->getUrlToPath($path);
        try {
            $this->getDirectory($path);

            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * @throws GuzzleException
     */
    protected function ensureValidPath(string $path)
    {
        if (str_contains($path, '/')) {
            $this->ensureDirectoryExists(dirname($path));
        }
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config|null $config
     * @return void
     * @throws Exception|GuzzleException
     */
    public function write(string $path, string $contents, Config $config = null): void
    {
        if (strlen($contents) > 4194304) {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $contents);
            rewind($stream);
            $this->writeStream($path, $stream, $config);

            return;
        }

        $path = trim($path, '/');
        $this->ensureValidPath($path);

        $file_name = basename($path);
        $parentItem = $this->getUrlToPath(dirname($path));
        $this->graph
            ->createRequest(
                'PUT',
                $this->getDriveItemUrl($parentItem) . ":/$file_name:/content"
            )
            ->addHeaders([
                'Content-Type' => 'text/plain',
            ])
            ->attachBody($contents)
            ->execute();
    }

    private function getUploadSessionUrl(string $path): string
    {
        return "/$this->options['directory_type']/$this->drive/items/root:/$path:/createUploadSession";
    }

    /**
     * @throws GraphException
     * @throws GuzzleException
     */
    public function createUploadSession($path): UploadSession
    {
        return $this->graph->createRequest('POST', $this->getUploadSessionUrl($path))->setReturnType(UploadSession::class)->execute();
    }

    /**
     * @param string $path
     * @param $contents
     * @param Config|null $config
     * @return void
     * @throws Exception|GuzzleException
     */
    public function writeStream(string $path, $contents, Config $config = null): void
    {
        $path = trim($path, '/');
        $this->ensureValidPath($path);
        $upload_session = $this->createUploadSession($path);
        $upload_url = $upload_session->getUploadUrl();

        $meta = fstat($contents);
        $chunk_size = $config->withDefaults($this->options)->get('chunk_size');
        $offset = 0;

        $guzzle = new Http();
        while ($chunk = fread($contents, $chunk_size)) {
            $this->writeChunk($guzzle, $upload_url, $meta['size'], $chunk, $offset);
            $offset += $chunk_size;
        }
    }

    /**
     * @throws GraphException
     * @throws Exception
     */
    private function writeChunk(Http $http, string $upload_url, int $file_size, string $chunk, int $first_byte, int $retries = 0): void
    {
        $last_byte_pos = $first_byte + strlen($chunk) - 1;
        $headers = [
            'Content-Range' => "bytes $first_byte-$last_byte_pos/$file_size",
            'Content-Length' => strlen($chunk),
        ];

        $response = $http->put(
            $upload_url,
            [
                'headers' => $headers,
                'body' => $chunk,
                'timeout' => $this->options['request_timeout'],
            ]
        );

        if ($response->status() === 404) {
            throw new Exception('Upload URL has expired, please create new upload session');
        }

        if ($response->status() === 429) {
            sleep($response->header('Retry-After')[0] ?? 1);
            $this->writeChunk($http, $upload_url, $file_size, $chunk, $first_byte, $retries + 1);
        }

        if ($response->status() >= 500) {
            if ($retries > 9) {
                throw new Exception('Upload failed after 10 attempts.');
            }
            sleep(pow(2, $retries));
            $this->writeChunk($http, $upload_url, $file_size, $chunk, $first_byte, $retries + 1);
        }

        if (($file_size - 1) == $last_byte_pos) {
            if ($response->status() === 409) {
                throw new Exception('File name conflict. A file with the same name already exists at target destination.');
            }

            if (in_array($response->status(), [200, 201])) {
                $response = new GraphResponse(
                    $this->graph->createRequest('', ''),
                    $response->body(),
                    $response->status(),
                    $response->headers()
                );

                $response->getResponseAsObject(DriveItem::class);

                return;
            }

            throw new Exception(
                'Unknown error occurred while uploading last part of file. HTTP response code is ' . $response->status()
            );
        }

        if ($response->status() !== 202) {
            throw new Exception('Unknown error occurred while trying to upload file chunk. HTTP status code is ' . $response->status());
        }

    }

    /**
     * @param string $path
     * @return string
     * @throws Exception|GuzzleException
     */
    public function read(string $path): string
    {
        try {
            if (!($object = $this->readStream($path))) {
                throw new UnableToReadFile('Unable to read file at ' . $path);
            }

            $object['contents'] = stream_get_contents($object['stream']);
            unset($object['stream']);

            return $object['contents'];
        } catch (Exception $e) {
            throw new Exception($e);
        }
    }

    /**
     * @param string $path
     * @return array
     * @throws GuzzleException
     * @throws Exception
     */
    public function readStream(string $path): array
    {
        try {
            $path = $this->getUrlToPath($path);

            $driveItem = $this->getDriveItem($path);
            //ensure we're dealing with a file
            if ($driveItem->getFile() == null) {
                throw new UnableToReadFile("Drive item at $path is not a file");
            }
            $download_url = $driveItem->getProperties()['@microsoft.graph.downloadUrl'];

            $http = new Http();
            $response = $http->get(
                $download_url,
            );

            $stream = StreamWrapper::getResource($response->body());

            return compact('stream');
        } catch (Exception $e) {
            throw new Exception($e);
        }
    }

    /**
     * @param string $path
     * @return void
     * @throws GuzzleException
     * @throws Exception
     */
    public function delete(string $path): void
    {
        try {
            $path = $this->getUrlToPath($path);

            $this->graph
                ->createRequest(
                    'DELETE',
                    $this->getDriveItemUrl($path)
                )
                ->execute()
                ->getBody();
        } catch (Exception $e) {
            throw new Exception($e);
        }
    }

    /**
     * @param string $path
     * @return void
     * @throws GuzzleException
     * @throws Exception
     */
    public function deleteDirectory(string $path): void
    {
        try {
            $this->delete($path);
        } catch (Exception $e) {
            throw new Exception($e);
        }
    }

    /**
     * @param string $path
     * @param Config|null $config
     * @return void
     * @throws GuzzleException
     * @throws Exception
     */
    public function createDirectory(string $path, Config $config = null): void
    {
        try {
            $newDirPathArray = explode('/', $path);
            $newDirName = array_pop($newDirPathArray);
            $parentItem = count($newDirPathArray)
                ? $this->getUrlToPath(implode('/', $newDirPathArray))
                : $this->getDriveRootUrl();

            $this->graph
                ->createRequest(
                    'POST',
                    $this->getDriveItemUrl($parentItem) . '/children'
                )
                ->attachBody([
                    'name' => $newDirName,
                    'folder' => new stdClass(),
                ])
                ->setReturnType(DriveItem::class)
                ->execute();
        } catch (Exception $e) {
            throw new Exception($e);
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
        throw UnableToSetVisibility::atLocation($path, 'Unsupported Operation');
    }

    /**
     * @param string $path
     * @return FileAttributes
     * @throws Exception
     */
    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'Unsupported Operation');
    }

    /**
     * @param string $path
     * @return FileAttributes
     * @throws Exception|GuzzleException
     */
    public function mimeType(string $path): FileAttributes
    {
        try {
            $item = $this->getDriveItem(
                $path = $this->getUrlToPath($path)
            );

            return FileAttributes::fromArray([
                StorageAttributes::ATTRIBUTE_PATH => $path,
                StorageAttributes::ATTRIBUTE_MIME_TYPE => $item->getFile()
                    ? $item->getFile()->getMimeType()
                    : null,
            ]);
        }
        catch (Exception $e) {
            throw new Exception($e);
        }
    }

    /**
     * @param string $path
     * @return FileAttributes
     * @throws Exception|GuzzleException
     */
    public function lastModified(string $path): FileAttributes
    {
        try {
            return FileAttributes::fromArray([
                StorageAttributes::ATTRIBUTE_PATH => $path,
                StorageAttributes::ATTRIBUTE_LAST_MODIFIED => $this->getDriveItem(
                    $this->getUrlToPath($path)
                )
                    ->getLastModifiedDateTime()
                    ->getTimestamp(),
            ]);
        }
        catch (Exception $e) {
            throw new Exception($e);
        }
    }

    /**
     * @param string $path
     * @return FileAttributes
     * @throws Exception|GuzzleException
     */
    public function fileSize(string $path): FileAttributes
    {
        try {
            return FileAttributes::fromArray([
                StorageAttributes::ATTRIBUTE_PATH => $path,
                StorageAttributes::ATTRIBUTE_FILE_SIZE => $this->getDriveItem(
                    $this->getUrlToPath($path)
                )->getSize(),
            ]);
        }
        catch (Exception $e) {
            throw new Exception($e);
        }
    }


    /**
     * @param string $path
     * @param bool $deep
     * @return iterable
     * @throws Exception|GuzzleException
     */

    public function listContents(string $path, bool $deep = true): iterable
    {
        try {
            $path = $path ? $this->getUrlToPath($path) . ':/children' : '/' . $this->options['directory_type'] . '/' . $this->drive . '/root/children';

            /** @var DriveItem[] $items */
            $items = [];
            $request = $this->graph
                ->createCollectionRequest('GET', $path)
                ->setReturnType(DriveItem::class);
            while (!$request->isEnd()) {
                $items = array_merge($items, $request->getPage());
            }
            if ($deep) {
                $folders = array_filter($items, fn ($item) => $item->getFolder() !== null);
                while (count($folders)) {
                    $folder = array_pop($folders);
                    $folder_path = $folder->getParentReference()->getPath() . DIRECTORY_SEPARATOR . $folder->getName();
                    $children = $this->getChildren($folder_path);
                    $items = array_merge($items, $children);
                    $folders = array_merge($folders, array_filter($children, fn ($child) => $child->getFolder() !== null));
                }
            }

            return $this->convertDriveItemsToStorageAttributes($items);

        } catch (Exception $e) {
            throw new Exception($e);
        }
    }

    private function convertDriveItemsToStorageAttributes(array $drive_items): array
    {
        return array_map(function (DriveItem $item) {
            $class = $item->getFile() ? FileAttributes::class : DirectoryAttributes::class;
            $path = $item->getParentReference()->getPath() . DIRECTORY_SEPARATOR . $item->getName();
            $driveLessPath = array_reverse(explode('root:', $path, 2))[0];
            return $class::fromArray([
                StorageAttributes::ATTRIBUTE_TYPE => $item->getFile() ? StorageAttributes::TYPE_FILE : StorageAttributes::TYPE_DIRECTORY,
                StorageAttributes::ATTRIBUTE_PATH => $driveLessPath,
                StorageAttributes::ATTRIBUTE_LAST_MODIFIED => $item->getLastModifiedDateTime()->getTimestamp(),
                StorageAttributes::ATTRIBUTE_FILE_SIZE => $item->getSize(),
                StorageAttributes::ATTRIBUTE_MIME_TYPE => $item->getFile()
                    ? $item->getFile()->getMimeType()
                    : null,
                'visibility' => 'public',
            ]);
        }, $drive_items);
    }

    /**
     * @throws GuzzleException
     * @throws GraphException
     */
    private function getChildren($directory): array
    {
        $path = $directory . ':/children';
        $request = $this->graph
            ->createCollectionRequest('GET', $path)
            ->setReturnType(DriveItem::class);
        /** @var DriveItem[] $items */
        $items = [];
        while (!$request->isEnd()) {
            $items = array_merge($items, $request->getPage());
        }

        return $items;
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
            $destination = trim($destination, '/');
            $this->ensureValidPath($destination);
            $source = $this->getUrlToPath($source);

            $newFilePathArray = explode('/', $destination);
            $newFileName = array_pop($newFilePathArray);
            $newPath = count($newFilePathArray)
                ? $this->getUrlToPath(implode('/', $newFilePathArray))
                : $this->getDriveRootUrl();

            $this->graph
                ->createRequest(
                    'PATCH',
                    $this->getDriveItemUrl($source)
                )
                ->attachBody([
                    'parentReference' => [
                        'driveId' => $this->getDrive()['id'],
                        'id' => $this->getFile($newPath)->getId(),
                    ],
                    'name' => $newFileName,
                ])
                ->execute()
                ->getBody();
        } catch (Exception|GuzzleException $e) {
            throw new Exception($e);
        }
    }

    /**
     * @param string $source
     * @param string $destination
     * @param Config|null $config
     * @return void
     * @throws Exception
     */
    public function copy(string $source, string $destination, Config $config = null): void
    {
        try {
            $destination = trim($destination, '/');
            $this->ensureValidPath($destination);

            $source = $this->getUrlToPath($source);

            $newFilePathArray = explode('/', $destination);
            $newFileName = array_pop($newFilePathArray);
            $newPath = count($newFilePathArray)
                ? $this->getUrlToPath(implode('/', $newFilePathArray))
                : $this->getDriveRootUrl();

            $this->graph
                ->createRequest(
                    'POST',
                    $this->getDriveItemUrl($source) . '/copy'
                )
                ->attachBody([
                    'parentReference' => [
                        'driveId' => $this->getDrive()['id'],
                        'id' => $this->getFile($newPath)->getId(),
                    ],
                    'name' => $newFileName,
                ])
                ->execute()
                ->getBody();

        } catch (Exception|GuzzleException $e) {
            throw new Exception($e);
        }
    }

    /**
     * @throws GraphException
     * @throws GuzzleException
     */
    private function getFileAttributes(string $path): FileAttributes
    {
        $file = $this->getDriveItem($path);

        return new FileAttributes(
            $path,
            $file->getSize(),
            null,
            $file->getLastModifiedDateTime()->getTimestamp(),
            $file->getFile()->getMimeType(),
            $file->getFile()->getProperties(),
        );
    }

    /**
     *
     * @throws GuzzleException
     * @throws GraphException
     */
    private function getDrive(): array
    {
        return $this->graph
            ->createRequest(
                'GET',
                str_replace('root', '', $this->getDriveRootUrl())
            )
            ->execute()
            ->getBody();
    }

    /**
     * @throws GuzzleException
     */
    protected function ensureDirectoryExists(string $path)
    {
        $directories = explode('/', $path);
        $current_path = '';
        foreach ($directories as $directory) {
            $current_path = trim($current_path .= '/' . $directory, '/');
            if (!$this->directoryExists($current_path)) {
                $this->createDirectory($current_path, new Config());
            }
        }
    }

    /**
     * @throws GraphException
     * @throws GuzzleException
     */
    public function getFile(string $path): File
    {
        return $this->graph
            ->createRequest('GET', $path)
            ->setReturnType(File::class)
            ->execute();
    }

    /**
     * @throws GuzzleException
     * @throws GraphException
     */
    public function getDirectory(string $path): Directory
    {
        return $this->graph
            ->createRequest('GET', $path)
            ->setReturnType(Directory::class)
            ->execute();
    }

    /**
     * @throws GuzzleException
     * @throws GraphException
     */
    public function getDriveItem(string $path): DriveItem
    {
        return $this->graph
            ->createRequest('GET', $path)
            ->setReturnType(DriveItem::class)
            ->execute();
    }

    public function setDrive(string $drive)
    {
        $this->drive = $drive;
    }
}
