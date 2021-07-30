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

namespace PhpProfiler\Lib\PhpInternals\Types\Zend;

/** @psalm-immutable */
final class Opline
{
    public function __construct(
        public int $op1,
        public int $op2,
        public int $result,
        public int $extended_value,
        public int $lineno,
        public int $opcode,
        public int $op1_type,
        public int $op2_type,
        public int $result_type,
    ) {
    }
}
