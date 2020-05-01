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

namespace PhpProfiler\Command;

use FilesystemIterator;
use IteratorAggregate;
use SplFileInfo;

/**
 * Class CommandFinder
 * @package App\Command
 */
final class CommandEnumerator implements IteratorAggregate
{
    private FilesystemIterator $command_files_iterator;

    /**
     * CommandEnumerator constructor.
     * @param FilesystemIterator $command_files_iterator
     */
    public function __construct(FilesystemIterator $command_files_iterator)
    {
        $this->command_files_iterator = $command_files_iterator;
    }

    /**
     * @inheritDoc
     */
    public function getIterator()
    {
        /** @var SplFileInfo $command_file_info */
        foreach ($this->command_files_iterator as $command_file_info) {
            $class_name = $command_file_info->getBasename('.php');
            $namespace = $command_file_info->getPathInfo()->getFilename();
            yield "PhpProfiler\\Command\\{$namespace}\\$class_name";
        }
    }
}
