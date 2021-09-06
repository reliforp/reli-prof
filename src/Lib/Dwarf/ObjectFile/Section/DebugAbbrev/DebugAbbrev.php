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

namespace PhpProfiler\Lib\Dwarf\ObjectFile\Section\DebugAbbrev;

use PhpProfiler\Lib\Dwarf\Abbreviation\AbbreviationDeclaration;

final class DebugAbbrev
{
    /** @param AbbreviationDeclaration[] $abbreviation_declarations */
    public function __construct(
        public array $abbreviation_declarations,
    ) {
    }
}
