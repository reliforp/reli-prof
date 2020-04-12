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



use Exception;
use Traversable;

/**
 * Class CommandFinder
 * @package App\Command
 */
class CommandEnumerator implements \IteratorAggregate
{
    /**
     * @inheritDoc
     */
    public function getIterator()
    {
        foreach (new \GlobIterator(__DIR__ . '/*/*Command.php') as $command_file_info) {
            $class_name = $command_file_info->getBasename('.php');
            $namespace = $command_file_info->getPathInfo()->getFilename();
            yield "PhpProfiler\\Command\\{$namespace}\\$class_name";
        }
    }
}