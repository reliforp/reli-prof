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

namespace Reli\Inspector\MemoryDump;

use DI\Container;
use DI\ContainerBuilder;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\File\PathResolver\MappedPathResolver;
use Reli\Lib\File\PathResolver\ProcessPathResolver;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\Inspector\MemoryDump\FastPath\FastPathReader;
use Reli\Inspector\MemoryDump\FastPath\RegionByteProvider;

use function DI\autowire;

final class MemoryDumpReaderFactory
{
    private const MAGIC = "RDUMP\0\0\0";

    public function __construct(
        private ContainerBuilder $container_builder,
    ) {
    }

    /**
     * @param array<string, string> $path_mapping
     * @param int $read_buffer_size Read-ahead buffer size for dump reads.
     *     Larger values trade memory for fewer fseek/fread syscalls.
     *     0 disables buffering. Default 256 KiB.
     */
    public function createFromPath(
        string $file_path,
        array $path_mapping,
        int $read_buffer_size = 256 * 1024,
        bool $disable_fast_path = false,
        bool $disable_bin_walk = false,
    ): MemoryDumpReader {
        $fp = fopen($file_path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("failed to open file: {$file_path}");
        }
        try {
            $parsed = $this->parse($fp);
        } finally {
            fclose($fp);
        }

        $process_memory_map = new ProcessMemoryMap($parsed['memory_areas']);
        $path_resolver = new MappedPathResolver($path_mapping);
        $region_index = $parsed['region_index'];

        $memory_reader = new DumpFileMemoryReader(
            $file_path,
            $region_index,
            $process_memory_map,
            $path_resolver,
            $read_buffer_size,
        );

        $container = $this->container_builder
            ->addDefinitions(
                require __DIR__ . '/../../../config/di.php'
            )
            ->addDefinitions([
                MemoryReaderInterface::class => $memory_reader,
                ProcessMemoryMapCreatorInterface::class =>
                    new class ($process_memory_map) implements ProcessMemoryMapCreatorInterface {
                        public function __construct(
                            private ProcessMemoryMap $process_memory_map,
                        ) {
                        }
                        #[\Override]
                        public function getProcessMemoryMap(int $pid): ProcessMemoryMap
                        {
                            return $this->process_memory_map;
                        }
                    },
                ProcessPathResolver::class => autowire(MappedPathResolver::class)
                    ->constructorParameter('path_map', $path_mapping)
            ])
            ->build()
        ;

        /** @var value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */
        $php_version = $parsed['php_version'];

        // Build FastPathReader for dump analysis
        $fast_path = null;
        /** @var class-string<FastPathReader> $fast_path_class */
        $fast_path_class = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$php_version}\\FastPathReader";
        if (!$disable_fast_path && class_exists($fast_path_class)) {
            $fast_fp = fopen($file_path, 'rb');
            if ($fast_fp !== false) {
                $fast_path = new $fast_path_class(
                    new RegionByteProvider($region_index, $fast_fp),
                );
            }
        }

        $bg_address = $this->resolveBgAddress(
            $parsed['module_globals'],
            $parsed['pid'],
            $php_version,
            $container,
        );

        return new MemoryDumpReader(
            $container->get(MemoryLocationsCollector::class),
            $parsed['pid'],
            $php_version,
            $parsed['eg_address'],
            $parsed['cg_address'],
            bg_address: $bg_address,
            rss_bytes: $parsed['rss_bytes'],
            fast_path: $fast_path,
            disable_bin_walk: $disable_bin_walk,
        );
    }

    /**
     * Tier-1 hit wins (address came from the live process at dump time
     * and is authoritative). Tier-1 miss falls through to PhpGlobalsFinder
     * against the dump-backed reader; that rescues older dumps written
     * before `basic_globals` was persisted, but only when the binary view
     * is reachable (`--include-binary` at capture or `--dependency-root`
     * at analyze). Finder failures silently degrade to null and
     * EmitModulesJob short-circuits — same observable behaviour as the
     * pre-v3 baseline.
     *
     * @param array<string, int> $module_globals
     * @param value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    private function resolveBgAddress(
        array $module_globals,
        int $pid,
        string $php_version,
        Container $container,
    ): ?int {
        if (isset($module_globals['basic_globals'])) {
            return $module_globals['basic_globals'];
        }

        try {
            /** @var PhpGlobalsFinder $finder */
            $finder = $container->get(PhpGlobalsFinder::class);
            /** @var TargetPhpSettings<value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_settings */
            $target_settings = new TargetPhpSettings(php_version: $php_version);
            return $finder->findBasicGlobals(
                new ProcessSpecifier($pid),
                $target_settings,
            );
        } catch (\Throwable $e) {
            Log::debug(
                'tier-2 basic_globals resolve failed: ' . $e->getMessage(),
            );
            return null;
        }
    }

    /**
     * Parse the dump file header and memory map, building an index of
     * region offsets without loading region data into memory.
     *
     * @param resource $fp
     * @return array{
     *     pid: int,
     *     php_version: string,
     *     eg_address: int,
     *     cg_address: int,
     *     rss_bytes: int|null,
     *     module_globals: array<string, int>,
     *     memory_areas: ProcessMemoryArea[],
     *     region_index: list<array{address: int, size: int, file_offset: int}>,
     * }
     */
    private function parse($fp): array
    {
        // Header
        $magic = fread($fp, 8);
        if ($magic !== self::MAGIC) {
            throw new \RuntimeException("invalid dump file: bad magic");
        }
        $format_version = $this->readUint32($fp);
        if ($format_version < 1 || $format_version > 3) {
            throw new \RuntimeException("unsupported dump format version: {$format_version}");
        }
        $php_version = $this->readString($fp);
        $pid = $this->readInt64($fp);
        $eg_address = $this->readInt64($fp);
        $cg_address = $this->readInt64($fp);
        // v2 added rss_bytes (signed int64; -1 = unavailable) right
        // after cg_address. v1 dumps don't carry it.
        $rss_bytes = null;
        if ($format_version >= 2) {
            $raw = $this->readInt64($fp);
            $rss_bytes = $raw === -1 ? null : $raw;
        }
        // v3 added a module-globals map after rss_bytes. v1/v2 dumps
        // fall back to tier-2 resolution (or null) in createFromPath.
        $module_globals = [];
        if ($format_version >= 3) {
            $entry_count = $this->readUint32($fp);
            for ($i = 0; $i < $entry_count; $i++) {
                $key = $this->readString($fp);
                $address = $this->readInt64($fp);
                $module_globals[$key] = $address;
            }
        }
        $memory_map_count = $this->readUint32($fp);
        $region_count = $this->readUint32($fp);

        // Memory map entries
        $memory_areas = [];
        for ($i = 0; $i < $memory_map_count; $i++) {
            $begin = $this->readString($fp);
            $end = $this->readString($fp);
            $file_offset = $this->readString($fp);
            $attrs = fread($fp, 4);
            if ($attrs === false || strlen($attrs) !== 4) {
                throw new \RuntimeException("failed to read memory area attributes");
            }
            $attribute = new ProcessMemoryAttribute(
                ord($attrs[0]) !== 0,
                ord($attrs[1]) !== 0,
                ord($attrs[2]) !== 0,
                ord($attrs[3]) !== 0,
            );
            $device_id = $this->readString($fp);
            $inode = $this->readInt64($fp);
            $name = $this->readString($fp);

            $memory_areas[] = new ProcessMemoryArea(
                $begin,
                $end,
                $file_offset,
                $attribute,
                $device_id,
                $inode,
                $name,
            );
        }

        // Build region index: record file offsets without reading data
        $region_index = [];
        for ($i = 0; $i < $region_count; $i++) {
            $address = $this->readInt64($fp);
            $size = $this->readInt64($fp);
            $data_offset = ftell($fp);
            if ($data_offset === false) {
                throw new \RuntimeException("failed to get file position");
            }
            $region_index[] = [
                'address' => $address,
                'size' => $size,
                'file_offset' => $data_offset,
            ];
            // Skip past the data without reading it
            fseek($fp, $size, SEEK_CUR);
        }

        return [
            'pid' => $pid,
            'php_version' => $php_version,
            'eg_address' => $eg_address,
            'cg_address' => $cg_address,
            'rss_bytes' => $rss_bytes,
            'module_globals' => $module_globals,
            'memory_areas' => $memory_areas,
            'region_index' => $region_index,
        ];
    }

    /** @param resource $fp */
    private function readUint32($fp): int
    {
        $data = fread($fp, 4);
        if ($data === false || strlen($data) !== 4) {
            throw new \RuntimeException("failed to read uint32");
        }
        /** @var array{val: int} */
        $result = unpack('Vval', $data);
        return $result['val'];
    }

    /** @param resource $fp */
    private function readInt64($fp): int
    {
        $data = fread($fp, 8);
        if ($data === false || strlen($data) !== 8) {
            throw new \RuntimeException("failed to read int64");
        }
        /** @var array{val: int} */
        $result = unpack('Pval', $data);
        return $result['val'];
    }

    /** @param resource $fp */
    private function readString($fp): string
    {
        $len = $this->readUint32($fp);
        if ($len === 0) {
            return '';
        }
        $data = fread($fp, $len);
        if ($data === false || strlen($data) !== $len) {
            throw new \RuntimeException("failed to read string of length {$len}");
        }
        return $data;
    }
}
