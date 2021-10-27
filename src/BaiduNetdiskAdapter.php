<?php

declare(strict_types=1);

namespace Hrb981027\FlysystemBaiduNetdisk;

use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Hrb981027\BaiduNetdisk\Client;
use Hrb981027\BaiduNetdisk\Exception\InvalidClientException;
use Hrb981027\BaiduNetdisk\Param\Client\FileMetas\Data as FileMetasData;
use Hrb981027\BaiduNetdisk\Param\Client\GetListAll\Data as GetListAllData;
use Hrb981027\BaiduNetdisk\Param\Client\Manger\Data as MangerData;
use Hrb981027\BaiduNetdisk\Param\Client\OneUpload\Data as OneUploadData;
use Hrb981027\BaiduNetdisk\Param\Client\Search\Data as SearchData;
use Hyperf\Guzzle\ClientFactory;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;

class BaiduNetdiskAdapter implements FilesystemAdapter
{
    protected GuzzleHttpClient $guzzleHttpClient;
    protected string $accessToken;
    protected string $root;
    protected Client $client;

    public function __construct(ClientFactory $clientFactory, array $config = [])
    {
        $this->guzzleHttpClient = $clientFactory->create();
        $this->accessToken = $config['access_token'];
        $this->root = isset($config['root']) ? $config['root'] . '/' : '/';
        $this->client = make(Client::class, [
            'accessToken' => $this->accessToken
        ]);
    }

    /**
     * @throws InvalidClientException
     */
    public function fileExists(string $path): bool
    {
        $path = $this->root . $path;

        $pathInfo = pathinfo($path);

        $result = $this->client->search(new SearchData([
            'key' => $pathInfo['basename'],
            'dir' => $pathInfo['dirname']
        ]));

        return !empty($result['list']);
    }

    /**
     * @throws InvalidClientException
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->writeStream($path, $contents, $config);
    }

    /**
     * @throws InvalidClientException
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $path = $this->root . $path;

        $tmpFile = '/tmp/' . generateUUID();

        file_put_contents($tmpFile, $contents);

        $this->client->oneUpload(new OneUploadData([
            'path' => $path,
            'r_type' => $config->get('r_type', 0),
            'local_path' => $tmpFile
        ]));

        unlink($tmpFile);
    }

    /**
     * @throws InvalidClientException
     * @throws GuzzleException
     */
    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        if (empty($stream)) {
            return '';
        }

        $result = stream_get_contents($stream);

        fclose($stream);

        return $result === false ? '' : $result;
    }

    /**
     * @throws InvalidClientException
     * @throws GuzzleException
     */
    public function readStream(string $path)
    {
        $path = $this->root . $path;

        $pathInfo = pathinfo($path);

        $result = $this->client->search(new SearchData([
            'key' => $pathInfo['basename'],
            'dir' => $pathInfo['dirname']
        ]));

        if (!isset($result['list'][0])) {
            return;
        }

        $id = $result['list'][0]['fs_id'];

        $info = $this->client->fileMetas(new FileMetasData([
            'fsids' => [$id],
            'dlink' => true
        ]));

        $dlink = $info['list'][0]['dlink'];

        $savePath = '/tmp/' . generateUUID();

        $this->guzzleHttpClient->get($dlink . '&access_token=' . $this->accessToken, [
            'headers' => [
                'User-Agent' => 'pan.baidu.com'
            ],
            'sink' => $savePath
        ]);

        return fopen($savePath, 'r');
    }

    /**
     * @throws InvalidClientException
     */
    public function delete(string $path): void
    {
        $path = $this->root . $path;

        $this->client->manager('delete', new MangerData([
            'file_list' => [
                $path
            ]
        ]));
    }

    /**
     * @throws InvalidClientException
     */
    public function deleteDirectory(string $path): void
    {
        $this->delete($path);
    }

    /**
     * @throws InvalidClientException
     */
    public function createDirectory(string $path, Config $config): void
    {
        $path = $this->root . $path;

        $this->client->oneUpload(new OneUploadData([
            'path' => $path,
            'is_dir' => true,
            'r_type' => $config->get('r_type', 0)
        ]));
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // TODO: Implement setVisibility() method.
    }

    public function visibility(string $path): FileAttributes
    {
        // TODO: Implement visibility() method.
    }

    public function mimeType(string $path): FileAttributes
    {
        // TODO: Implement mimeType() method.
    }

    /**
     * @throws InvalidClientException
     */
    public function lastModified(string $path): FileAttributes
    {
        $path = $this->root . $path;

        $pathInfo = pathinfo($path);

        $result = $this->client->search(new SearchData([
            'key' => $pathInfo['basename'],
            'dir' => $pathInfo['dirname']
        ]));

        return new FileAttributes(path: $path, lastModified: $result['list'][0]['server_mtime'] ?? null);
    }

    /**
     * @throws InvalidClientException
     */
    public function fileSize(string $path): FileAttributes
    {
        $path = $this->root . $path;

        $pathInfo = pathinfo($path);

        $result = $this->client->search(new SearchData([
            'key' => $pathInfo['basename'],
            'dir' => $pathInfo['dirname']
        ]));

        return new FileAttributes($path, $result['list'][0]['size'] ?? null);
    }

    /**
     * @throws InvalidClientException
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $path = $this->root . $path;

        $list = $this->client->getListAll(new GetListAllData([
            'path' => $path,
            'recursion' => $deep
        ]));

        foreach ($list['list'] as $item) {
            switch ($item['isdir']) {
                case 0:
                    yield new FileAttributes(path: $item['path'], fileSize: $item['size'], lastModified: $item['server_mtime']);

                    break;
                case 1:
                    yield new DirectoryAttributes(path: $item['path'], lastModified: $item['server_mtime']);

                    break;
            }
        }
    }

    /**
     * @throws InvalidClientException
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $source = '/' . $source;
        $destination = '/' . $destination;

        $destinationInfo = pathinfo($destination);

        $this->client->manager('move', new MangerData([
            'async' => 1,
            'file_list' => [
                [
                    'path' => $source,
                    'dest' => $destinationInfo['dirname'],
                    'newname' => $destinationInfo['basename'],
                    // overwrite 和 skip 无效
                    'ondup' => 'fail'
                ]
            ]
        ]));
    }

    /**
     * @throws InvalidClientException
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $source = '/' . $source;
        $destination = '/' . $destination;

        $destinationInfo = pathinfo($destination);

        $this->client->manager('copy', new MangerData([
            'async' => 1,
            'file_list' => [
                [
                    'path' => $source,
                    'dest' => $destinationInfo['dirname'],
                    'newname' => $destinationInfo['basename'],
                    // overwrite 和 skip 无效
                    'ondup' => 'fail'
                ]
            ]
        ]));
    }
}