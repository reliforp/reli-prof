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
use Reli\Lib\Elf\SymbolResolver\Elf64ReverseSymbolResolver;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;

final class NativeSymbolResolver
{
    /** @var array<string, Elf64ReverseSymbolResolver|null> module path => resolver */
    private array $resolvers = [];

    /** @var array<string, int> module path => base address */
    private array $moduleBaseAddresses = [];

    private Elf64Parser $elfParser;
    private DebugFileLocator $debugFileLocator;
    private ?PerfMapSymbolResolver $perfMapResolver = null;

    public function __construct(
        private ProcessMemoryMap $memoryMap,
        ?DebugFileLocator $debugFileLocator = null,
        ?PerfMapSymbolResolver $perfMapResolver = null,
    ) {
        $this->elfParser = new Elf64Parser(new LittleEndianReader());
        $this->debugFileLocator = $debugFileLocator ?? new DebugFileLocator();
        $this->perfMapResolver = $perfMapResolver;
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
        if ($module_path === '' || $module_path[0] === '[') {
            // Anonymous mapping or special region - try perf map (JIT code)
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
        // Try loading from the binary itself
        $resolver = $this->tryLoadFromFile($modulePath);
        if ($resolver !== null) {
            return $resolver;
        }

        // Fallback: try separate debug file for .symtab
        $debugFile = $this->debugFileLocator->locate($modulePath);
        if ($debugFile !== null) {
            return $this->tryLoadFromFile($debugFile);
        }

        return null;
    }

    private function tryLoadFromFile(string $filePath): ?Elf64ReverseSymbolResolver
    {
        if (!is_file($filePath)) {
            return null;
        }

        $binary = file_get_contents($filePath);
        if ($binary === false) {
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
