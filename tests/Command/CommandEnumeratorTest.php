<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Command;

use GlobIterator;
use PHPUnit\Framework\TestCase;

class CommandEnumeratorTest extends TestCase
{
    public function testCanEnumerateCommands()
    {
        $enumerator = new CommandEnumerator(
            new GlobIterator(__DIR__ . '/CommandEnumeratorTestData/*/*Command.php')
        );
        $result = [];
        foreach ($enumerator as $command) {
            $result[] = $command;
        }
        $this->assertSame(
            [
                'PhpProfiler\Command\Test1Directory\Test1Command',
                'PhpProfiler\Command\Test1Directory\Test2Command',
                'PhpProfiler\Command\Test2Directory\Test3Command',
                'PhpProfiler\Command\Test2Directory\Test4Command',
            ],
            $result
        );
    }
}
