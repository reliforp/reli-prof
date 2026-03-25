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

namespace Reli\Inspector\Output\OutputChannel;

/**
 * Direct stream output channel that bypasses Symfony Console's
 * OutputFormatter (and its Unicode normalization overhead).
 */
final class StreamOutputChannel implements OutputChannel
{
    /** @param resource $stream */
    public function __construct(
        private $stream,
    ) {
    }

    #[\Override]
    public function output(string $content): void
    {
        fwrite($this->stream, $content);
    }
}
