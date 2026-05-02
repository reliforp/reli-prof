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

namespace Reli\Command\Inspector;

use FFI;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Raw byte read from a live target or rdump file.
 *
 * The bottom of the orphan-allocation drill-down funnel (design doc
 * section E.2): when {@see BinHistogramPass} surfaces "+16,041 bin[32 B]"
 * but the detector pipeline can't earn a HIGH-confidence label, the user
 * peeks the sample address and decodes the bytes by hand. Replaces the
 * `dd if=/proc/<pid>/mem | od -A x -t x1z` recipe with a self-contained
 * command that also works against rdump files for offline analysis.
 */
final class MemoryPeekCommand extends ReliCommand
{
    /** Hard cap on read length to prevent runaway dumps. */
    private const MAX_LENGTH = 64 * 1024;

    private const RDUMP_MAGIC = "RDUMP\0\0\0";

    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    public function __construct(
        private MemoryReaderInterface $memory_reader,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:peek')
            ->setDescription(
                'read raw bytes from a live target process or rdump file'
                . ' and print as hexdump'
            )
        ;
        $this->addOption(
            'pid',
            'p',
            InputOption::VALUE_REQUIRED,
            'PID of the live target process',
        );
        $this->addOption(
            'rdump',
            null,
            InputOption::VALUE_REQUIRED,
            'path to an rdump file to read from instead of a live target',
        );
        $this->addOption(
            'address',
            'a',
            InputOption::VALUE_REQUIRED,
            'remote address to read from (hex 0x... or decimal)',
        );
        $this->addOption(
            'length',
            'l',
            InputOption::VALUE_REQUIRED,
            sprintf(
                'number of bytes to read (default 256, max %d)',
                self::MAX_LENGTH,
            ),
            '256',
        );
        $this->addMemoryLimitOption();
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyMemoryLimit($input, $output);

        $pid_opt = $input->getOption('pid');
        $rdump_opt = $input->getOption('rdump');
        if (
            ($pid_opt === null && $rdump_opt === null)
            || ($pid_opt !== null && $rdump_opt !== null)
        ) {
            $output->writeln(
                '<error>exactly one of --pid or --rdump must be supplied</error>',
            );
            return 1;
        }

        $address_opt = $input->getOption('address');
        if (!is_string($address_opt) || $address_opt === '') {
            $output->writeln('<error>--address is required</error>');
            return 1;
        }
        $address = $this->parseAddress($address_opt);
        if ($address === null) {
            $output->writeln(
                "<error>could not parse address: {$address_opt}</error>",
            );
            return 1;
        }

        $length_opt = $input->getOption('length');
        $length = is_string($length_opt) || is_int($length_opt)
            ? (int)$length_opt
            : 256;
        if ($length <= 0) {
            $output->writeln('<error>--length must be positive</error>');
            return 1;
        }
        if ($length > self::MAX_LENGTH) {
            $output->writeln(sprintf(
                '<error>--length capped at %d bytes</error>',
                self::MAX_LENGTH,
            ));
            return 1;
        }

        try {
            $bytes = $pid_opt !== null
                ? $this->readFromLive((int)$pid_opt, $address, $length)
                : $this->readFromRdump((string)$rdump_opt, $address, $length);
        } catch (\Throwable $e) {
            $output->writeln(
                "<error>read failed: {$e->getMessage()}</error>",
            );
            return 1;
        }

        $output->writeln(sprintf(
            '<info>%d bytes from 0x%x</info>',
            strlen($bytes),
            $address,
        ));
        $output->writeln('');
        $output->write($this->hexdump($bytes, $address));
        return 0;
    }

    private function parseAddress(string $raw): ?int
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '0x') || str_starts_with($raw, '0X')) {
            $hex = substr($raw, 2);
            if ($hex === '' || !ctype_xdigit($hex)) {
                return null;
            }
            return (int)hexdec($hex);
        }
        if (!ctype_digit($raw)) {
            return null;
        }
        return (int)$raw;
    }

    private function readFromLive(int $pid, int $address, int $length): string
    {
        $cdata = $this->memory_reader->read($pid, $address, $length);
        return FFI::string($cdata, $length);
    }

    private function readFromRdump(string $path, int $address, int $length): string
    {
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("failed to open rdump file: {$path}");
        }
        try {
            return $this->readSliceFromRdump($fp, $address, $length);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Walk the rdump's regions (no data load) and read the slice
     * intersecting the requested range. Supports cross-region reads by
     * concatenating slices in order. Reads stop at the first gap; the
     * caller sees whatever was reachable.
     *
     * @param resource $fp
     */
    private function readSliceFromRdump($fp, int $address, int $length): string
    {
        $magic = fread($fp, 8);
        if ($magic !== self::RDUMP_MAGIC) {
            throw new \RuntimeException('not an rdump file');
        }
        $version = $this->readUint32($fp);
        if ($version !== 1 && $version !== 2) {
            throw new \RuntimeException(
                "unsupported rdump format version: {$version}",
            );
        }
        // Header tail: php_version, pid, eg_address, cg_address,
        // (rss_bytes if v2), memory_map_count, region_count.
        $this->readString($fp);
        fseek($fp, 8 * 3, SEEK_CUR);
        if ($version >= 2) {
            fseek($fp, 8, SEEK_CUR);
        }
        $memory_map_count = $this->readUint32($fp);
        $region_count = $this->readUint32($fp);

        // Skip the memory-map section — we only need the regions.
        for ($i = 0; $i < $memory_map_count; $i++) {
            $this->readString($fp); // begin
            $this->readString($fp); // end
            $this->readString($fp); // file_offset
            fseek($fp, 4, SEEK_CUR); // attribute bytes
            $this->readString($fp); // device_id
            fseek($fp, 8, SEEK_CUR); // inode
            $this->readString($fp); // name
        }

        // Region table walk: for each region, decide what slice (if any)
        // intersects [address, address+length). Read just that slice.
        $end = $address + $length;
        /** @var list<array{addr: int, slice: string}> $hits */
        $hits = [];
        for ($i = 0; $i < $region_count; $i++) {
            $region_addr = $this->readInt64($fp);
            $region_size = $this->readInt64($fp);
            $region_end = $region_addr + $region_size;
            $data_offset = ftell($fp);
            if ($data_offset === false) {
                throw new \RuntimeException('ftell failed');
            }

            $overlap_start = max($region_addr, $address);
            $overlap_end = min($region_end, $end);
            if ($overlap_start < $overlap_end) {
                $slice_offset = $overlap_start - $region_addr;
                $slice_len = $overlap_end - $overlap_start;
                if (fseek($fp, $data_offset + $slice_offset, SEEK_SET) !== 0) {
                    throw new \RuntimeException('seek into region data failed');
                }
                $slice = fread($fp, $slice_len);
                if ($slice === false || strlen($slice) !== $slice_len) {
                    throw new \RuntimeException(
                        "failed to read {$slice_len} bytes at region offset {$slice_offset}",
                    );
                }
                $hits[] = ['addr' => $overlap_start, 'slice' => $slice];
            }

            // Advance past the region's data block so the next iteration
            // is positioned at the next region header.
            if (fseek($fp, $data_offset + $region_size, SEEK_SET) !== 0) {
                throw new \RuntimeException('seek past region data failed');
            }
        }

        if ($hits === []) {
            throw new \RuntimeException(sprintf(
                'address 0x%x is not covered by any region in this rdump',
                $address,
            ));
        }

        usort(
            $hits,
            static fn(array $a, array $b): int => $a['addr'] <=> $b['addr'],
        );

        // Stitch slices in address order; bail at the first gap so we
        // don't quietly fabricate non-contiguous bytes.
        $expected = $hits[0]['addr'];
        $out = '';
        foreach ($hits as $hit) {
            if ($hit['addr'] !== $expected) {
                break;
            }
            $out .= $hit['slice'];
            $expected = $hit['addr'] + strlen($hit['slice']);
        }
        return $out;
    }

    /**
     * Render bytes as a 16-byte-per-line hexdump with address column +
     * ASCII gutter. Mirrors `od -A x -t x1z` shape so users coming from
     * the manual workflow read the output without relearning anything.
     */
    private function hexdump(string $bytes, int $base_addr): string
    {
        $out = '';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i += 16) {
            $row = substr($bytes, $i, 16);
            $hex_parts = [];
            $ascii = '';
            $row_len = strlen($row);
            for ($j = 0; $j < 16; $j++) {
                if ($j < $row_len) {
                    $byte = ord($row[$j]);
                    $hex_parts[] = sprintf('%02x', $byte);
                    $ascii .= ($byte >= 0x20 && $byte < 0x7f)
                        ? chr($byte)
                        : '.';
                } else {
                    $hex_parts[] = '  ';
                    $ascii .= ' ';
                }
                if ($j === 7) {
                    $hex_parts[] = '';
                }
            }
            $out .= sprintf(
                "%016x  %s  |%s|\n",
                $base_addr + $i,
                implode(' ', $hex_parts),
                $ascii,
            );
        }
        return $out;
    }

    /** @param resource $fp */
    private function readUint32($fp): int
    {
        $data = fread($fp, 4);
        if ($data === false || strlen($data) !== 4) {
            throw new \RuntimeException('failed to read uint32');
        }
        /** @var array{val: int} $result */
        $result = unpack('Vval', $data);
        return $result['val'];
    }

    /** @param resource $fp */
    private function readInt64($fp): int
    {
        $data = fread($fp, 8);
        if ($data === false || strlen($data) !== 8) {
            throw new \RuntimeException('failed to read int64');
        }
        /** @var array{val: int} $result */
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
