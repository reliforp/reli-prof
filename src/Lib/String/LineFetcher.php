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

use Webmozart\Assert\Assert;

final class LineFetcher
{
    /** @return iterable<string> */
    public function createIterable(string $string): iterable
    {
        $line = strtok($string, "\n");
        if ($line === false) {
            // strtok returning false on the first call is documented only
            // for the "input is just the delimiter" case; if some other
            // input were to land here the function would silently yield
            // an empty string and drop the caller's data.
            Assert::same($string, "\n");
            yield  '';
            return;
        }

        while ($line !== false) {
            yield $line;
            $line = strtok("\n");
        }
    }
}
