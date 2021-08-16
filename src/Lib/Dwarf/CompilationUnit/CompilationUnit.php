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

namespace PhpProfiler\Lib\Dwarf\CompilationUnit;

use PhpProfiler\Lib\Dwarf\CompilationUnit\UnitHeader\TypeUnitHeader;
use PhpProfiler\Lib\Dwarf\CompilationUnit\UnitHeader\UnitHeader;
use PhpProfiler\Lib\Dwarf\DebuggingInformationEntry\CompileUnitDIE;
use PhpProfiler\Lib\Dwarf\DebuggingInformationEntry\PartialUnitDIE;

final class CompilationUnit
{
    public function __construct(
        public UnitHeader $unit_header,
        public CompileUnitDIE|PartialUnitDIE $unit_die,
        public ?TypeUnitHeader $type_unit_header,
    ) {
    }
}