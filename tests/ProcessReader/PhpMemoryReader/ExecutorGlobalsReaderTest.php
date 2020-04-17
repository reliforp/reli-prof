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
use PhpProfiler\Lib\Process\MemoryReader;
use PHPUnit\Framework\TestCase;

class ExecutorGlobalsReaderTest extends TestCase
{

    public function testFindCurrentExecuteData()
    {
        $executor_globals_reader = new ExecutorGlobalsReader(
            new MemoryReader(),
            new ZendTypeReader(ZendTypeReader::V80)
        );
        $name = $executor_globals_reader->readCurrentFunctionName(4836, 0x56062bd8d990);
        var_dump($name);
    }
}
