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

use FilesystemIterator;
use IteratorAggregate;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;

/** @implements IteratorAggregate<class-string<Command>> */
final class CommandEnumerator implements IteratorAggregate
{
    public function __construct(
        private FilesystemIterator $command_files_iterator
    ) {
    }

    /** @return \Generator<class-string<Command>> */
    public function getIterator()
    {
        /** @var SplFileInfo $command_file_info */
        foreach ($this->command_files_iterator as $command_file_info) {
            $class_name = $command_file_info->getBasename('.php');
            $namespace = $command_file_info->getPathInfo()?->getFilename();
            assert(!is_null($namespace));
            $result = "Reli\\Command\\{$namespace}\\$class_name";
            assert(is_subclass_of($result, Command::class));
            yield $result;
        }
    }
}
