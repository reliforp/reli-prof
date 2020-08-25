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

namespace PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message;

final class TargetRegexMessage
{
    public string $regex;

    /**
     * TargetRegexMessage constructor.
     * @param string $regex
     */
    public function __construct(string $regex)
    {
        $this->regex = $regex;
    }
}
