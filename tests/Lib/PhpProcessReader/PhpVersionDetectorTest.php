<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\PhpProcessReader;

use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use PhpProfiler\Lib\Elf\Parser\Elf64Parser;
use PhpProfiler\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use PhpProfiler\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use PhpProfiler\Lib\File\NativeFileReader;
use PhpProfiler\Lib\PhpInternals\ZendTypeReaderCreator;
use PhpProfiler\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReader;
use PhpProfiler\Lib\Process\ProcessSpecifier;
use PHPUnit\Framework\TestCase;

class PhpVersionDetectorTest extends TestCase
{
    /** @var resource|null */
    private $child = null;

    protected function tearDown(): void
    {
        if (!is_null($this->child)) {
            $child_status = proc_get_status($this->child);
            if (is_array($child_status)) {
                if ($child_status['running']) {
                    posix_kill($child_status['pid'], SIGKILL);
                }
            }
        }
    }

    public function testTryDetection()
    {
        var_dump(PHP_VERSION);
        $memory_reader = new MemoryReader();
        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            $memory_reader,
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new NativeFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            new LittleEndianReader()
        );
        $php_version_detector = new PhpVersionDetector(
            $php_symbol_reader_creator,
            new ZendTypeReaderCreator(),
            $memory_reader
        );

        $this->child = proc_open(
            [
                PHP_BINARY,
                '-r',
                'fputs(STDOUT, "a\n");fgets(STDIN);'
            ],
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w']
            ],
            $pipes
        );

        fgets($pipes[1]);
        $child_status = proc_get_status($this->child);

        $process_memory_map = $process_memory_map_creator->getProcessMemoryMap(
            $child_status['pid']
        );
        var_dump($process_memory_map);

        /** @var int $child_status['pid'] */
        $php_version = $php_version_detector->tryDetection(
            new ProcessSpecifier($child_status['pid']),
            new TargetPhpSettings()
        );
        $this->assertIsString($php_version);
    }
}
