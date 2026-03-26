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

namespace Reli\Lib\Dwarf;

use Reli\Lib\ByteStream\ByteReaderInterface;

final class Leb128
{
    /**
     * @return array{int, int} [value, bytesConsumed]
     */
    public static function decodeUnsigned(ByteReaderInterface $data, int $offset): array
    {
        $result = 0;
        $shift = 0;
        $bytes_consumed = 0;

        do {
            $byte = $data[$offset + $bytes_consumed];
            $result |= ($byte & 0x7f) << $shift;
            $shift += 7;
            $bytes_consumed++;
        } while ($byte & 0x80);

        return [$result, $bytes_consumed];
    }

    /**
     * @return array{int, int} [value, bytesConsumed]
     */
    public static function decodeSigned(ByteReaderInterface $data, int $offset): array
    {
        $result = 0;
        $shift = 0;
        $bytes_consumed = 0;

        do {
            $byte = $data[$offset + $bytes_consumed];
            $result |= ($byte & 0x7f) << $shift;
            $shift += 7;
            $bytes_consumed++;
        } while ($byte & 0x80);

        if ($shift < 64 && ($byte & 0x40)) {
            $result |= -(1 << $shift);
        }

        return [$result, $bytes_consumed];
    }
}
