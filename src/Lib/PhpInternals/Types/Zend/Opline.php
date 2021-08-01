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
    public int $op1;
    public int $op2;
    public int $result;
    public int $extended_value;
    public int $lineno;
    public int $opcode;
    public int $op1_type;
    public int $op2_type;
    public int $result_type;

    public function __construct(
        int $op1,
        int $op2,
        int $result,
        int $extended_value,
        int $lineno,
        int $opcode,
        int $op1_type,
        int $op2_type,
        int $result_type
    ) {
        $this->op1 = $op1;
        $this->op2 = $op2;
        $this->result = $result;
        $this->extended_value = $extended_value;
        $this->lineno = $lineno;
        $this->opcode = $opcode;
        $this->op1_type = $op1_type;
        $this->op2_type = $op2_type;
        $this->result_type = $result_type;
    }
}
