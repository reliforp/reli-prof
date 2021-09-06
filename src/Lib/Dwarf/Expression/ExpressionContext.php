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

namespace PhpProfiler\Lib\Dwarf\Expression;

use PhpProfiler\Lib\Dwarf\CompilationUnit\CompilationUnit;
use PhpProfiler\Lib\Dwarf\ObjectFile\Executable;

final class ExpressionContext
{
    public function getCompilationUnit(): CompilationUnit
    {

    }

    public function getExecutableFile(): Executable
    {

    }
}
