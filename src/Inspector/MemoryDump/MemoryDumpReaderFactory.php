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

use DI\ContainerBuilder;
use FFI\CData;
use Reli\Lib\File\PathResolver\MappedPathResolver;
use Reli\Lib\File\PathResolver\ProcessPathResolver;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocationsCollector;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\FFI\FFIHelper;

use function DI\autowire;

final class MemoryDumpReaderFactory
{
    private const MAGIC = "RELIMEM\0";

    public function __construct(
        private ContainerBuilder $container_builder,
    ) {
    }

    /** @param array<string, string> $path_mapping */
    public function createFromPath(string $file_path, array $path_mapping): MemoryDumpReader
    {
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
        $regions = $parsed['regions'];

        $memory_reader = new class ($regions, $process_memory_map, $path_resolver) implements MemoryReaderInterface {
            /**
             * @param array<array{address: int, size: int, data: string}> $regions
             */
            public function __construct(
                private array $regions,
                private ProcessMemoryMap $process_memory_map,
                private MappedPathResolver $path_resolver,
            ) {
            }

            #[\Override]
            public function read(int $pid, int $remote_address, int $size): CData
            {
                foreach ($this->regions as $region) {
                    $region_start = $region['address'];
                    $region_end = $region_start + $region['size'];
                    if ($remote_address >= $region_start && ($remote_address + $size) <= $region_end) {
                        $offset = $remote_address - $region_start;
                        $data = substr($region['data'], $offset, $size);
                        $cdata_buffer = FFIHelper::new("unsigned char[$size]");
                        if (is_null($cdata_buffer)) {
                            throw new \RuntimeException("failed to allocate memory");
                        }
                        \FFI::memcpy($cdata_buffer, $data, $size);
                        /** @var \FFI\CArray<int> */
                        return $cdata_buffer;
                    }
                }

                // Fallback: try to read from binary files via memory map (for read-only segments)
                $memory_areas = $this->process_memory_map->findByAddress($remote_address);
                foreach ($memory_areas as $memory_area) {
                    if ($memory_area->name !== '' && !$memory_area->attribute->write) {
                        $resolved_path = $this->path_resolver->resolve($pid, $memory_area->name);
                        if (file_exists($resolved_path)) {
                            $file_fp = fopen($resolved_path, 'rb');
                            if ($file_fp === false) {
                                continue;
                            }
                            $offset = $remote_address - hexdec($memory_area->begin);
                            fseek($file_fp, (int)hexdec($memory_area->file_offset) + $offset);
                            $data = fread($file_fp, $size);
                            fclose($file_fp);
                            if ($data === false) {
                                continue;
                            }
                            $cdata_buffer = FFIHelper::new("unsigned char[$size]");
                            if (is_null($cdata_buffer)) {
                                throw new \RuntimeException("failed to allocate memory");
                            }
                            \FFI::memcpy($cdata_buffer, $data, $size);
                            /** @var \FFI\CArray<int> */
                            return $cdata_buffer;
                        }
                    }
                }

                throw new \RuntimeException(
                    "no memory region found for address: 0x" . dechex($remote_address) . " (size: {$size})"
                );
            }
        };

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
        return new MemoryDumpReader(
            $container->get(MemoryLocationsCollector::class),
            $parsed['pid'],
            $php_version,
            $parsed['eg_address'],
            $parsed['cg_address'],
        );
    }

    /**
     * @param resource $fp
     * @return array{
     *     pid: int,
     *     php_version: string,
     *     eg_address: int,
     *     cg_address: int,
     *     memory_areas: ProcessMemoryArea[],
     *     regions: array<array{address: int, size: int, data: string}>,
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
        if ($format_version !== 1) {
            throw new \RuntimeException("unsupported dump format version: {$format_version}");
        }
        $php_version = $this->readString($fp);
        $pid = $this->readInt64($fp);
        $eg_address = $this->readInt64($fp);
        $cg_address = $this->readInt64($fp);
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

        // Memory regions
        $regions = [];
        for ($i = 0; $i < $region_count; $i++) {
            $address = $this->readInt64($fp);
            $size = $this->readInt64($fp);
            $data = fread($fp, $size);
            if ($data === false || strlen($data) !== $size) {
                throw new \RuntimeException("failed to read memory region data at address 0x" . dechex($address));
            }
            $regions[] = [
                'address' => $address,
                'size' => $size,
                'data' => $data,
            ];
        }

        return [
            'pid' => $pid,
            'php_version' => $php_version,
            'eg_address' => $eg_address,
            'cg_address' => $cg_address,
            'memory_areas' => $memory_areas,
            'regions' => $regions,
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
