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
    public function __construct(
        private string $baseDirectory,
    ) {
    }

    /** @return array<array-key, mixed>|null */
    public function get(BinaryFingerprint $fingerprint, string $namespace): ?array
    {
        $path = $this->getPath($fingerprint, $namespace);
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

    /** @param array<array-key, mixed> $data */
    public function set(BinaryFingerprint $fingerprint, string $namespace, array $data): void
    {
        $path = $this->getPath($fingerprint, $namespace);
        $dir = dirname($path);
        AppDirectory::ensureDirectoryExists($dir);

        $pid = getmypid();
        $tmpPath = $path . '.tmp.' . ($pid !== false ? $pid : mt_rand());
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        if (@file_put_contents($tmpPath, $json) === false) {
            return;
        }
        @rename($tmpPath, $path);
    }

    private function getPath(BinaryFingerprint $fingerprint, string $namespace): string
    {
        return $this->baseDirectory
            . '/' . $fingerprint->getCacheKey()
            . '/' . $namespace . '.json';
    }
}
