<?php

namespace jgivoni\Flysystem\Cache;

use League\Flysystem\CalculateChecksumFromStream;
use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToReadFile;
use Psr\Cache\CacheItemPoolInterface;

class CacheAdapter implements FilesystemAdapter, ChecksumProvider
{
    use CacheItemsTrait;
    use CalculateChecksumFromStream;

    public function __construct(
        protected readonly FilesystemAdapter $adapter,
        protected readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function fileExists(string $path): bool
    {
        $item = $this->getCacheItem($path);

        if (!$item->isHit()) {
            $fileExists = $this->adapter->fileExists($path);

            if ($fileExists) {
                $item->set(new FileAttributes(
                    path: $path
                ));

                $this->saveCacheItem($item);
            }
        } elseif ($item->get() instanceof FileAttributes) {
            $fileExists = true;
        }

        return $fileExists ?? \false;
    }

    /**
     * @inheritdoc
     */
    public function directoryExists(string $path): bool
    {
        $item = $this->getCacheItem($path);

        if (!$item->isHit()) {
            $directoryExists = $this->adapter->directoryExists($path);

            if ($directoryExists) {
                $item->set(new DirectoryAttributes(
                    path: $path
                ));

                $this->saveCacheItem($item);
            }
        } elseif ($item->get() instanceof DirectoryAttributes) {
            $directoryExists = true;
        }

        return $directoryExists ?? \false;
    }

    /**
     * @inheritdoc
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->adapter->write($path, $contents, $config);

        $this->addCacheEntry($path, new FileAttributes($path));
    }

    /**
     * @inheritdoc
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->adapter->writeStream($path, $contents, $config);

        $this->addCacheEntry($path, new FileAttributes($path));
    }

    /**
     * @inheritdoc
     */
    public function read(string $path): string
    {
        $item = $this->getCacheItem($path);

        try {
            $contents = $this->adapter->read($path);
        } catch (UnableToReadFile $e) {
            if ($item->isHit()) {
                $this->deleteCacheItem($item);
            }

            throw $e;
        }

        if (!$item->isHit()) {
            $fileAttributes = new FileAttributes(
                path: $path,
            );

            $item->set($fileAttributes);

            $this->saveCacheItem($item);
        }

        return $contents;
    }

    /**
     * @inheritdoc
     */
    public function readStream(string $path)
    {
        $item = $this->getCacheItem($path);

        try {
            $resource = $this->adapter->readStream($path);
        } catch (UnableToReadFile $e) {
            if ($item->isHit()) {
                $this->deleteCacheItem($item);
            }

            throw $e;
        }

        if (!$item->isHit()) {
            $fileAttributes = new FileAttributes(
                path: $path,
            );

            $item->set($fileAttributes);

            $this->saveCacheItem($item);
        }

        return $resource;
    }

    /**
     * @inheritdoc
     */
    public function delete(string $path): void
    {
        $this->adapter->delete($path);

        $item = $this->getCacheItem($path);

        if ($item->isHit()) {
            $this->deleteCacheItem($item);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteDirectory(string $path): void
    {
        foreach ($this->adapter->listContents($path, true) as $storageAttributes) {
            /** @var StorageAttributes $storageAttributes */
            $item = $this->getCacheItem($storageAttributes->path());

            if ($item->isHit()) {
                $this->deleteCacheItem($item);
            }
        }

        $this->adapter->deleteDirectory($path);

        $item = $this->getCacheItem($path);

        if ($item->isHit()) {
            $this->deleteCacheItem($item);
        }
    }

    /**
     * @inheritdoc
     */
    public function createDirectory(string $path, Config $config): void
    {
        $this->adapter->createDirectory($path, $config);

        $this->addCacheEntry($path, new DirectoryAttributes($path));
    }

    /**
     * @inheritdoc
     */
    public function setVisibility(string $path, string $visibility): void
    {
        $this->adapter->setVisibility($path, $visibility);

        $item = $this->getCacheItem($path);

        if ($item->isHit()) {
            $storageAttributes = $item->get();

            if ($storageAttributes instanceof FileAttributes) {
                $fileAttributes = self::mergeFileAttributes(
                    fileAttributesBase: $storageAttributes,
                    fileAttributesExtension: new FileAttributes(
                        path: $path,
                        visibility: $visibility,
                    ),
                );

                $item->set($fileAttributes);
            } elseif ($storageAttributes instanceof DirectoryAttributes) {
                $directoryAttributes = self::mergeDirectoryAttributes(
                    directoryAttributesBase: $storageAttributes,
                    directoryAttributesExtension: new DirectoryAttributes(
                        path: $path,
                        visibility: $visibility,
                    ),
                );

                $item->set($directoryAttributes);
            }


            $this->saveCacheItem($item);
        }

        // We cannot create the cache item if it does not exist since we don't know if it's a file or a directory
    }

    /**
     * @inheritdoc
     */
    public function visibility(string $path): FileAttributes
    {
        return $this->getFileAttributes(
            path: $path,
            loader: function () use ($path) {
                return $this->adapter->visibility($path);
            },
            attributeAccessor: function (FileAttributes $fileAttributes) {
                return $fileAttributes->visibility();
            },
        );
    }

    /**
     * @inheritdoc
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->getFileAttributes(
            path: $path,
            loader: function () use ($path) {
                return $this->adapter->mimeType($path);
            },
            attributeAccessor: function (FileAttributes $fileAttributes) {
                return $fileAttributes->mimeType();
            },
        );
    }

    /**
     * @inheritdoc
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->getFileAttributes(
            path: $path,
            loader: function () use ($path) {
                return $this->adapter->lastModified($path);
            },
            attributeAccessor: function (FileAttributes $fileAttributes) {
                return $fileAttributes->lastModified();
            },
        );
    }

    /**
     * @inheritdoc
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->getFileAttributes(
            path: $path,
            loader: function () use ($path) {
                return $this->adapter->fileSize($path);
            },
            attributeAccessor: function (FileAttributes $fileAttributes) {
                return $fileAttributes->fileSize();
            },
        );
    }

    /**
     * @inheritdoc
     */
    public function checksum(string $path, Config $config): string
    {
        $algo = $config->get('checksum_algo');
        $metadataKey = isset($algo) ? 'checksum_' . $algo : 'checksum';

        $attributeAccessor = function (StorageAttributes $storageAttributes) use ($metadataKey) {
            if (\is_a($this->adapter, 'League\Flysystem\AwsS3V3\AwsS3V3Adapter')) {
                // Special optimization for AWS S3, but won't break if adapter not installed
                $etag = $storageAttributes->extraMetadata()['ETag'] ?? \null;
                if (isset($etag)) {
                    $checksum = trim($etag, '" ');
                }
            }

            return $checksum ?? $storageAttributes->extraMetadata()[$metadataKey] ?? \null;
        };

        $fileAttributes = $this->getFileAttributes(
            path: $path,
            loader: function () use ($path, $config, $metadataKey) {
                // This part is "mirrored" from FileSystem class to provide the fallback mechanism
                // and be able to cache the result
                try {
                    if (!$this->adapter instanceof ChecksumProvider) {
                        throw new ChecksumAlgoIsNotSupported;
                    }

                    $checksum = $this->adapter->checksum($path, $config);
                } catch (ChecksumAlgoIsNotSupported) {
                    $checksum = $this->calculateChecksumFromStream($path, $config);
                }

                return new FileAttributes($path, extraMetadata: [$metadataKey => $checksum]);
            },
            attributeAccessor: $attributeAccessor
        );

        return $attributeAccessor($fileAttributes);
    }

    /**
     * @inheritdoc
     */
    public function listContents(string $path, bool $deep): iterable
    {
        /** @var StorageAttributes|FileAttributes $storageAttributes */
        foreach ($this->adapter->listContents($path, $deep) as $storageAttributes) {
            $item = $this->getCacheItem($storageAttributes->path());

            if ($item->isHit()) {
                $cachedStorageAttributes = $item->get();

                if (
                    $cachedStorageAttributes instanceof FileAttributes &&
                    $storageAttributes instanceof FileAttributes
                ) {
                    $cachedStorageAttributes = self::mergeFileAttributes(
                        fileAttributesBase: $cachedStorageAttributes,
                        fileAttributesExtension: $storageAttributes,
                    );
                } elseif (
                    $cachedStorageAttributes instanceof DirectoryAttributes &&
                    $storageAttributes instanceof DirectoryAttributes
                ) {
                    $cachedStorageAttributes = self::mergeDirectoryAttributes(
                        directoryAttributesBase: $cachedStorageAttributes,
                        directoryAttributesExtension: $storageAttributes,
                    );
                }
            } else {
                $cachedStorageAttributes = \null;
            }

            $item->set($cachedStorageAttributes ?? $storageAttributes);

            $this->saveCacheItem($item);

            yield $storageAttributes;
        }
    }

    /**
     * @inheritdoc
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $this->adapter->move($source, $destination, $config);

        $itemSource = $this->getCacheItem($source);
        $itemDestination = $this->getCacheItem($destination);

        if ($itemSource->isHit()) {
            /** @var StorageAttributes $sourceStorageAttributes */
            $sourceStorageAttributes = $itemSource->get();

            $destinationStorageAttributes = $sourceStorageAttributes->withPath($destination);

            $this->deleteCacheItem($itemSource);
        } else {
            $destinationStorageAttributes = new FileAttributes(path: $destination);
        }

        $itemDestination->set($destinationStorageAttributes);

        $this->saveCacheItem($itemDestination);
    }

    /**
     * @inheritdoc
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $this->adapter->copy($source, $destination, $config);

        $itemSource = $this->getCacheItem($source);
        $itemDestination = $this->getCacheItem($destination);

        if ($itemSource->isHit()) {
            /** @var StorageAttributes $sourceStorageAttributes */
            $sourceStorageAttributes = $itemSource->get();

            $destinationStorageAttributes = $sourceStorageAttributes->withPath($destination);
        } else {
            $destinationStorageAttributes = new FileAttributes(path: $destination);
        }

        $itemDestination->set($destinationStorageAttributes);

        $this->saveCacheItem($itemDestination);
    }
}
