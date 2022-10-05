<?php

/**
 * This file is part of the sj-i/ package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Reli\Inspector\Output\TopLike;

use Reli\Lib\DateTime\OnDemandClock;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

final class TopLikeFormatterFactory
{
    public function __construct(
        private Terminal $terminal,
        private OnDemandClock $clock,
    ) {
    }

    public function create(
        string $target_regex,
        OutputInterface $output
    ): TopLikeFormatter {
        assert($output instanceof ConsoleOutputInterface);
        return new TopLikeFormatter(
            $target_regex,
            new TopLikeOutputter(
                $output,
                $this->terminal,
            ),
            $this->clock
        );
    }
}
