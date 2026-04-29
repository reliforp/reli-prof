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

use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MemoryDumpInspectCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    private const MAGIC = "RDUMP\0\0\0";

    public function __construct()
    {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:dump:inspect')
            ->setDescription('inspect a memory dump file header, memory map, and regions')
        ;
        $this->addArgument(
            'dump-file',
            InputArgument::REQUIRED,
            'path to the memory dump file'
        );
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $dump_file */
        $dump_file = $input->getArgument('dump-file');

        $fp = @fopen($dump_file, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("failed to open file: {$dump_file}");
        }
        try {
            $this->inspect($fp, $output);
        } finally {
            fclose($fp);
        }

        return 0;
    }

    /** @param resource $fp */
    private function inspect($fp, OutputInterface $output): void
    {
        // === Header ===
        $magic = fread($fp, 8);
        if ($magic !== self::MAGIC) {
            throw new \RuntimeException("invalid dump file: bad magic");
        }
        $format_version = $this->readUint32($fp);
        if ($format_version !== 1 && $format_version !== 2) {
            throw new \RuntimeException("unsupported dump format version: {$format_version}");
        }
        $php_version = $this->readString($fp);
        $pid = $this->readInt64($fp);
        $eg_address = $this->readInt64($fp);
        $cg_address = $this->readInt64($fp);
        $rss_bytes = null;
        if ($format_version >= 2) {
            $raw = $this->readInt64($fp);
            $rss_bytes = $raw === -1 ? null : $raw;
        }
        $memory_map_count = $this->readUint32($fp);
        $region_count = $this->readUint32($fp);

        $output->writeln('<info>=== Header ===</info>');
        $output->writeln("  Magic:            RDUMP");
        $output->writeln("  Format Version:   {$format_version}");
        $output->writeln("  PHP Version:      {$php_version}");
        $output->writeln("  PID:              {$pid}");
        $output->writeln("  EG Address:       0x" . dechex($eg_address));
        $output->writeln("  CG Address:       0x" . dechex($cg_address));
        $output->writeln(
            "  RSS at dump:      "
            . ($rss_bytes === null ? '(unavailable)' : (string)$rss_bytes . ' bytes')
        );
        $output->writeln("  Memory Map Count: {$memory_map_count}");
        $output->writeln("  Region Count:     {$region_count}");
        $output->writeln('');

        // === Memory Map ===
        $output->writeln('<info>=== Memory Map ===</info>');
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

            $perms = ($attribute->read ? 'r' : '-')
                . ($attribute->write ? 'w' : '-')
                . ($attribute->execute ? 'x' : '-')
                . ($attribute->protected ? 'p' : '-');

            $output->writeln(sprintf(
                '  %s-%s %s %s %s %d %s',
                $begin,
                $end,
                $perms,
                $file_offset,
                $device_id,
                $inode,
                $name,
            ));
        }
        $output->writeln('');

        // === Regions ===
        $output->writeln('<info>=== Regions ===</info>');
        $total_bytes = 0;
        for ($i = 0; $i < $region_count; $i++) {
            $address = $this->readInt64($fp);
            $size = $this->readInt64($fp);
            // skip region data without loading into memory
            fseek($fp, $size, SEEK_CUR);
            $total_bytes += $size;

            $output->writeln(sprintf(
                '  #%d  0x%s - 0x%s  (%s)',
                $i,
                dechex($address),
                dechex($address + $size),
                $this->formatSize($size),
            ));
        }
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Total: %d regions, %s</info>',
            $region_count,
            $this->formatSize($total_bytes),
        ));
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1f MiB', (float)$bytes / 1024.0 / 1024.0);
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KiB', (float)$bytes / 1024.0);
        }
        return sprintf('%d B', $bytes);
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
