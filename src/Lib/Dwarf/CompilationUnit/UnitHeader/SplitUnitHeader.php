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

final class SplitUnitHeader implements UnitHeader
{
    use UnitHeaderTrait;

    public function __construct(
        public int $unit_length,
        public int $version,
        public UnitType $unit_type,
        public int $address_size,
        public int $debug_abbrev_offset,
        public int $dwo_id,
    ) {
    }
}
