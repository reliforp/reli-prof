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

namespace Reli\Lib\Integer;

use function decbin;
use function max;
use function pack;
use function str_pad;
use function strlen;
use function strrev;
use function unpack;

final class UInt64
{
    public string $packed_value;

    public function __construct(
        public int $hi,
        public int $lo
    ) {
        $this->packed_value = $this->pack($hi, $lo);
    }

    public static function fromInt(int $i): self
    {
        return new self($i >> 32, $i & 0xffffffff);
    }

    private function pack(int $hi, int $lo): string
    {
        return pack('ll', $lo, $hi);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private function addDigitsViaString(string $a, string $b): string
    {
        $result = '';
        $carry = 0;
        $a = strrev($a);
        $b = strrev($b);

        $max_len = max(strlen($a), strlen($b));
        for ($i = 0; $i < $max_len; $i++) {
            $digit_a = (int)($a[$i] ?? 0);
            $digit_b = (int)($b[$i] ?? 0);

            $sum = $digit_a + $digit_b + $carry;
            $carry = (int)($sum >= 10);

            $result .= $sum % 10;
        }

        if ($carry) {
            $result .= '1';
        }

        return strrev($result);
    }

    private function twosComplementToUnsigned(string $binary_string): string
    {
        $binary_string = strrev($binary_string);

        $result = '0';
        $addend = '1';

        for ($i = 0; $i < strlen($binary_string); $i++) {
            if ($binary_string[$i] === '1') {
                $result = $this->addDigitsViaString($result, $addend);
            }
            $addend = $this->addDigitsViaString($addend, $addend);
        }

        return $result;
    }

    private function toString(): string
    {
        $int = $this->toInt();
        $binary_string = decbin($int);
        if ($int >= 0) {
            $binary_string = str_pad($binary_string, 64, '0', STR_PAD_LEFT);
        }

        return $this->twosComplementToUnsigned($binary_string);
    }

    public function toInt(): int
    {
        $result = unpack('Presult', $this->packed_value);
        if ($result === false) {
            throw new \LogicException('unpack failed');
        }
        return (int)$result['result'];
    }

    public function checkBitSet(int $bit_pos): bool
    {
        if ($bit_pos >= 32) {
            $bit_pos -= 32;
            return (bool)($this->hi & (1 << $bit_pos));
        }
        return (bool)($this->lo & (1 << $bit_pos));
    }
}
