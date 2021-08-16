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

trait UnitHeaderTrait
{
    public function getUnitLength(): int
    {
        return $this->unit_length;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getUnitType(): UnitType
    {
        return $this->unit_type;
    }

    public function getAddressSize(): int
    {
        return $this->address_size;
    }

    public function getDebugAbbrevOffset(): int
    {
        return $this->debug_abbrev_offset;
    }
}
