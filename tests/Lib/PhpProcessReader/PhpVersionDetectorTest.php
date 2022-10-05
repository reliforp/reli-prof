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

namespace PhpProfiler\Lib\PhpProcessReader;

use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use PhpProfiler\Lib\Elf\Parser\Elf64Parser;
use PhpProfiler\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use PhpProfiler\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use PhpProfiler\Lib\File\CatFileReader;
use PhpProfiler\Lib\PhpInternals\ZendTypeReaderCreator;
use PhpProfiler\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReader;
use PhpProfiler\Lib\Process\ProcessSpecifier;
use PHPUnit\Framework\TestCase;

class PhpVersionDetectorTest extends TestCase
{
    public function testDetectVersion()
    {
        $memory_reader = new MemoryReader();
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
        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            $memory_reader,
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
            ),
            ProcessMemoryMapCreator::create(),
            new LittleEndianReader()
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            new LittleEndianReader(),
            $memory_reader = new MemoryReader()
        );
        $module_registry_address = $php_globals_finder->findModuleRegistry(
            new ProcessSpecifier($child_status['pid']),
            new TargetPhpSettings()
        );
        $php_version_detector = new PhpVersionDetector(
            $php_globals_finder,
            $memory_reader,
            new ZendTypeReaderCreator()
        );
        $version = $php_version_detector->detectPhpVersion(
            $child_status['pid'],
            $module_registry_address,
        );
        $expected_version = join(
            '',
            [
                'v',
                PHP_MAJOR_VERSION,
                PHP_MINOR_VERSION,
            ]
        );
        $this->assertSame($expected_version, $version);
    }
}
