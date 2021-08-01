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

namespace PhpProfiler\Lib\PhpProcessReader;

use PhpProfiler\Lib\PhpInternals\Types\Zend\Opline;

/** @psalm-immutable */
final class CallFrame
{
    public string $class_name;
    public string $function_name;
    public string $file_name;
    public ?Opline $opline;

    public function __construct(
        string $class_name,
        string $function_name,
        string $file_name,
        ?Opline $opline
    ) {
        $this->class_name = $class_name;
        $this->function_name = $function_name;
        $this->file_name = $file_name;
        $this->opline = $opline;
    }

    public function getFullyQualifiedFunctionName(): string
    {
        if ($this->class_name === '') {
            return $this->function_name;
        }
        return "{$this->class_name}::{$this->function_name}";
    }
}
