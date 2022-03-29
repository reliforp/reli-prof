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

namespace PhpProfiler\Inspector\Output\OutputChannel;

use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleOutputChannel implements OutputChannel
{
    public function __construct(
        private OutputInterface $output,
    ) {
    }

    public function output(string $content): void
    {
        $this->output->write($content);
    }
}