<?php

namespace App\Services\Storage;

use App\Services\StorageManager;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use RuntimeException;

class StorageRoutingAdapter implements FilesystemAdapter
{
    public function __construct(private readonly StorageManager $storage)
    {
    }

    public function fileExists(string $path): bool { return $this->storage->exists($path); }
    public function directoryExists(string $path): bool { return $this->storage->directoryExists($path); }
    public function write(string $path, string $contents, Config $config): void { $this->storage->write($path, $contents, $config); }
    public function writeStream(string $path, $contents, Config $config): void { $this->storage->writeStream($path, $contents, $config); }
    public function read(string $path): string { return $this->storage->read($path); }
    public function readStream(string $path) { return $this->storage->readStream($path); }
    public function delete(string $path): void { $this->storage->delete($path); }
    public function deleteDirectory(string $path): void { $this->storage->deleteDirectory($path); }
    public function createDirectory(string $path, Config $config): void { $this->storage->createDirectory($path); }

    public function setVisibility(string $path, string $visibility): void
    {
        if ($visibility === 'public') {
            throw new RuntimeException('Managed storage objects are private; use authorized application delivery.');
        }
    }

    public function visibility(string $path): FileAttributes { return $this->storage->attributes($path); }
    public function mimeType(string $path): FileAttributes { return $this->storage->attributes($path); }
    public function lastModified(string $path): FileAttributes { return $this->storage->attributes($path); }
    public function fileSize(string $path): FileAttributes { return $this->storage->attributes($path); }
    public function listContents(string $path, bool $deep): iterable { return $this->storage->listContents($path, $deep); }
    public function move(string $source, string $destination, Config $config): void { $this->storage->move($source, $destination); }
    public function copy(string $source, string $destination, Config $config): void { $this->storage->copy($source, $destination); }
}