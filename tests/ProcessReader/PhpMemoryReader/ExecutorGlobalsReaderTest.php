<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\ProcessReader\PhpMemoryReader;

use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReader;
use PhpProfiler\ProcessReader\PhpGlobalsFinder;
use PhpProfiler\ProcessReader\PhpSymbolReaderCreator;
use PHPUnit\Framework\TestCase;

class ExecutorGlobalsReaderTest extends TestCase
{
    /** @var resource|null */
    private $child = null;

    public function tearDown(): void
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

    public function testReadCurrentFunctionName()
    {
        $memory_reader = new MemoryReader();
        $executor_globals_reader = new ExecutorGlobalsReader(
            $memory_reader,
            new ZendTypeReader(ZendTypeReader::V74)
        );
        $this->child = proc_open(
            [
                PHP_BINARY,
                '-d extension=parallel.so',
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
        $php_globals_finder = new PhpGlobalsFinder(
            (new PhpSymbolReaderCreator($memory_reader))->create($child_status['pid'])
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals();
        $name = $executor_globals_reader->readCurrentFunctionName($child_status['pid'], $executor_globals_address);
        $this->assertSame('fgets', $name);
    }
}
