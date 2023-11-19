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

namespace Reli\Command;

use GlobIterator;
use Reli\BaseTestCase;

class CommandEnumeratorTest extends BaseTestCase
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
                'Reli\Command\Test1Directory\Test1Command',
                'Reli\Command\Test1Directory\Test2Command',
                'Reli\Command\Test2Directory\Test3Command',
                'Reli\Command\Test2Directory\Test4Command',
            ],
            $result
        );
    }
}
