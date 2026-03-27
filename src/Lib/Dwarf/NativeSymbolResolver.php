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

namespace Reli\Lib\Dwarf;

use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\DebugFileLocator;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprint;
use Reli\Lib\Elf\SymbolResolver\Elf64ReverseSymbolResolver;
use Reli\Lib\File\FileReaderInterface;
use Reli\Lib\File\NativeFileReader;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;

final class NativeSymbolResolver
{
    /** @var array<string, Elf64ReverseSymbolResolver|null> module path => resolver */
    private array $resolvers = [];

    /** @var array<string, int> module path => base address */
    private array $moduleBaseAddresses = [];

    /** @var array<string, bool> module path => is_file result */
    private array $isFileCache = [];

    private Elf64Parser $elfParser;
    private DebugFileLocator $debugFileLocator;
    private ?PerfMapSymbolResolver $perfMapResolver = null;
    private FileReaderInterface $fileReader;

    public function __construct(
        private ProcessMemoryMap $memoryMap,
        ?DebugFileLocator $debugFileLocator = null,
        ?PerfMapSymbolResolver $perfMapResolver = null,
        private string $processRoot = '',
        ?FileReaderInterface $fileReader = null,
        private ?BinaryAnalysisCache $binaryAnalysisCache = null,
    ) {
        $this->elfParser = new Elf64Parser(new LittleEndianReader());
        $this->debugFileLocator = $debugFileLocator ?? new DebugFileLocator();
        $this->perfMapResolver = $perfMapResolver;
        $this->fileReader = $fileReader ?? new NativeFileReader();
    }

    /**
     * @return array{string, int}|null [symbolName, offset] or null
     */
    public function resolve(int $absoluteAddress): ?array
    {
        $areas = $this->memoryMap->findByAddress($absoluteAddress);
        if ($areas === []) {
            return null;
        }

        $area = $areas[0];
        $module_path = $area->name;
        if ($module_path === '' || $module_path[0] === '[' || !$this->isFile($module_path)) {
            // Anonymous/special/non-file mapping (JIT buffer, /dev/zero, etc.) - try perf map
            return $this->perfMapResolver?->resolve($absoluteAddress);
        }

        $base_address = $this->getModuleBaseAddress($module_path, $area);
        $relative_address = $absoluteAddress - $base_address;

        $resolver = $this->getResolver($module_path);
        if ($resolver === null) {
            return null;
        }

        return $resolver->resolve($relative_address);
    }

    private function getResolver(string $modulePath): ?Elf64ReverseSymbolResolver
    {
        if (array_key_exists($modulePath, $this->resolvers)) {
            return $this->resolvers[$modulePath];
        }

        $resolver = $this->loadResolver($modulePath);
        $this->resolvers[$modulePath] = $resolver;
        return $resolver;
    }

    private function loadResolver(string $modulePath): ?Elf64ReverseSymbolResolver
    {
        // Try loading from disk cache
        $fingerprint = $this->getFingerprint($modulePath);
        if ($fingerprint !== null) {
            $resolver = $this->loadFromCache($fingerprint);
            if ($resolver !== null) {
                return $resolver;
            }
        }

        // Try loading from the binary itself
        $resolver = $this->tryLoadFromFile($modulePath);
        if ($resolver === null) {
            // Fallback: try separate debug file for .symtab
            $debugFile = $this->debugFileLocator->locate($modulePath);
            if ($debugFile !== null) {
                $resolver = $this->tryLoadFromFile($debugFile);
            }
        }

        if ($resolver !== null && $fingerprint !== null) {
            $this->saveToCache($fingerprint, $resolver);
        }

        return $resolver;
    }

    private function getFingerprint(string $modulePath): ?BinaryFingerprint
    {
        $areas = $this->memoryMap->findByNameRegex(preg_quote($modulePath, '/'));
        if ($areas === []) {
            return null;
        }
        $area = $areas[0];
        return new BinaryFingerprint(
            join('_', [$area->device_id, $area->inode_num, $area->name])
        );
    }

    private function loadFromCache(BinaryFingerprint $fingerprint): ?Elf64ReverseSymbolResolver
    {
        if ($this->binaryAnalysisCache === null) {
            return null;
        }
        $cached = $this->binaryAnalysisCache->get($fingerprint, 'native_symbols');
        if ($cached === null || !isset($cached['symbols']) || !is_array($cached['symbols'])) {
            return null;
        }
        /** @var array<array{int, int, string}> $symbols */
        $symbols = $cached['symbols'];
        return Elf64ReverseSymbolResolver::fromArray($symbols);
    }

    private function saveToCache(BinaryFingerprint $fingerprint, Elf64ReverseSymbolResolver $resolver): void
    {
        if ($this->binaryAnalysisCache === null) {
            return;
        }
        $this->binaryAnalysisCache->set($fingerprint, 'native_symbols', [
            'symbols' => $resolver->toArray(),
        ]);
    }

    private function resolvePath(string $path): string
    {
        if ($this->processRoot !== '' && str_starts_with($path, '/')) {
            return $this->processRoot . $path;
        }
        return $path;
    }

    private function isFile(string $path): bool
    {
        $resolved = $this->resolvePath($path);
        if (!isset($this->isFileCache[$path])) {
            $this->isFileCache[$path] = is_file($resolved);
        }
        return $this->isFileCache[$path];
    }

    private function tryLoadFromFile(string $filePath): ?Elf64ReverseSymbolResolver
    {
        $resolved = $this->resolvePath($filePath);

        try {
            $binary = $this->fileReader->readAll($resolved);
        } catch (\Throwable) {
            return null;
        }

        return Elf64ReverseSymbolResolver::loadFromBinary(
            $this->elfParser,
            new StringByteReader($binary),
        );
    }

    /**
     * @param \Reli\Lib\Process\MemoryMap\ProcessMemoryArea $area
     */
    private function getModuleBaseAddress(string $modulePath, $area): int
    {
        if (isset($this->moduleBaseAddresses[$modulePath])) {
            return $this->moduleBaseAddresses[$modulePath];
        }

        // Find the first mapping of this module (lowest address with offset 0)
        $all_areas = $this->memoryMap->findByNameRegex(preg_quote($modulePath, '/'));
        $base = hexdec($area->begin) - hexdec($area->file_offset);
        foreach ($all_areas as $a) {
            if ($a->file_offset === '0' || $a->file_offset === '00000000') {
                $candidate = hexdec($a->begin);
                if ($candidate < $base) {
                    $base = $candidate;
                }
            }
        }

        $this->moduleBaseAddresses[$modulePath] = $base;
        return $base;
    }
}
