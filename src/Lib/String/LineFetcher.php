<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace PhpProfiler\Lib\String;

/**
 * Class LineFetcher
 * @package PhpProfiler\Lib\String
 */
class LineFetcher
{
    /**
     * @param string $string
     * @return \Generator|void
     */
    public function createGenerator(string $string)
    {
        $line = strtok($string, "\n");
        if ($line === false) {
            yield $string;
            return;
        }

        while ($line !== false) {
            yield $line;
            $line = strtok("\n");
        }
    }
}