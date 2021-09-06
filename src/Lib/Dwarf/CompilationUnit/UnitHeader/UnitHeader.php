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

namespace PhpProfiler\Lib\Dwarf\CompilationUnit\UnitHeader;

use PhpProfiler\Lib\Dwarf\CompilationUnit\UnitType;

interface UnitHeader
{
    public function getUnitLength(): int;
    public function getVersion(): int;
    public function getUnitType(): UnitType;
    public function getAddressSize(): int;
    public function getDebugAbbrevOffset(): int;
}
