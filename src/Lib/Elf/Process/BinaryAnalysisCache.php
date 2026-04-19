<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Reli\Lib\Elf\Process;

use Reli\Lib\Directory\AppDirectory;

final class BinaryAnalysisCache
{
    private bool $enabled = true;
    private bool $useIgbinary;

    public function __construct(
        private string $baseDirectory,
    ) {
        $this->useIgbinary = extension_loaded('igbinary');
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function clear(): int
    {
        if (!is_dir($this->baseDirectory)) {
            return 0;
        }
        return $this->removeDirectoryContents($this->baseDirectory);
    }

    /** @return array<array-key, mixed>|null */
    public function get(BinaryFingerprint $fingerprint, string $namespace): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        // Try igbinary first, then JSON fallback
        if ($this->useIgbinary) {
            $data = $this->loadIgbinary($fingerprint, $namespace);
            if ($data !== null) {
                return $data;
            }
        }
        return $this->loadJson($fingerprint, $namespace);
    }

    /** @param array<array-key, mixed> $data */
    public function set(BinaryFingerprint $fingerprint, string $namespace, array $data): void
    {
        if (!$this->enabled) {
            return;
        }
        if ($this->useIgbinary) {
            $this->saveIgbinary($fingerprint, $namespace, $data);
        } else {
            $this->saveJson($fingerprint, $namespace, $data);
        }
    }

    /** @return array<array-key, mixed>|null */
    private function loadIgbinary(BinaryFingerprint $fingerprint, string $namespace): ?array
    {
        $path = $this->getBasePath($fingerprint, $namespace) . '.igbinary';
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        try {
            /** @psalm-suppress UndefinedFunction */
            $data = igbinary_unserialize($content);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    /** @return array<array-key, mixed>|null */
    private function loadJson(BinaryFingerprint $fingerprint, string $namespace): ?array
    {
        $path = $this->getBasePath($fingerprint, $namespace) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    /**
     * @param array<array-key, mixed> $data
     * @psalm-suppress UndefinedFunction,MixedAssignment,MixedArgument
     */
    private function saveIgbinary(BinaryFingerprint $fingerprint, string $namespace, array $data): void
    {
        $path = $this->getBasePath($fingerprint, $namespace) . '.igbinary';
        $serialized = igbinary_serialize($data);
        if ($serialized === false) {
            return;
        }
        $this->writeFile($path, $serialized);
    }

    /** @param array<array-key, mixed> $data */
    private function saveJson(BinaryFingerprint $fingerprint, string $namespace, array $data): void
    {
        $path = $this->getBasePath($fingerprint, $namespace) . '.json';
        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        $this->writeFile($path, $encoded);
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        AppDirectory::ensureDirectoryExists($dir);
        $pid = getmypid();
        $tmpPath = $path . '.tmp.' . ($pid !== false ? $pid : mt_rand());
        if (@file_put_contents($tmpPath, $content) === false) {
            return;
        }
        @rename($tmpPath, $path);
    }

    private function getBasePath(BinaryFingerprint $fingerprint, string $namespace): string
    {
        return $this->baseDirectory
            . '/' . $fingerprint->getCacheKey()
            . '/' . $namespace;
    }

    private function removeDirectoryContents(string $dir): int
    {
        $count = 0;
        $items = @scandir($dir);
        if ($items === false) {
            return 0;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $count += $this->removeDirectoryContents($path);
                @rmdir($path);
            } else {
                if (@unlink($path)) {
                    $count++;
                }
            }
        }
        return $count;
    }
}
