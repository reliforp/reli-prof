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

namespace Reli\Lib\String;

final class LineFetcher
{
    /** @return iterable<string> */
    public function createIterable(string $string): iterable
    {
        $line = strtok($string, "\n");
        if ($line === false) {
            assert($string === "\n");
            yield  '';
            return;
        }

        while ($line !== false) {
            yield $line;
            $line = strtok("\n");
        }
    }
}
