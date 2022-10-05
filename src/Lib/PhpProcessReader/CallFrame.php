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

namespace PhpProfiler\Lib\PhpProcessReader;

use PhpProfiler\Lib\PhpInternals\Types\Zend\Opline;

/** @psalm-immutable */
final class CallFrame
{
    public function __construct(
        public string $class_name,
        public string $function_name,
        public string $file_name,
        public ?Opline $opline
    ) {
    }

    public function getFullyQualifiedFunctionName(): string
    {
        if ($this->class_name === '') {
            return $this->function_name;
        }
        return "{$this->class_name}::{$this->function_name}";
    }

    public function getLineno(): int
    {
        if (is_null($this->opline)) {
            return -1;
        }
        return $this->opline->lineno;
    }

    public function getOpcodeName(): string
    {
        if (is_null($this->opline)) {
            return '';
        }
        return $this->opline->opcode->getName();
    }
}
