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

namespace PhpProfiler\Lib\Integer;

final class Leb128Decoder
{
    public function unsigned(string $buffer): int
    {
        $result = 0;
        $last_byte = false;
        $pos = 0;

        do {
            $current = ord($buffer[$pos]);
            if (($current & 0x80) === 0) {
                $last_byte = true;
            }
            $result |= ($current & 0x7f) << ($pos * 7);
            $pos++;
        } while (!$last_byte);

        return $result;
    }

    public function signed(string $buffer): int
    {
        $result = 0;
        $last_byte = false;
        $pos = 0;

        do {
            $current = ord($buffer[$pos]);
            if (($current & 0x80) === 0) {
                $last_byte = true;
            }
            $result |= ($current & 0x7f) << ($pos * 7);
            $pos++;
        } while (!$last_byte);

        if ($current & 0x40) {
            $result |= (-1 << $pos * 7);
        }

        return $result;
    }
}
