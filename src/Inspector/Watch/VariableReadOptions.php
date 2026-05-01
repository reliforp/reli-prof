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

namespace Reli\Inspector\Watch;

/**
 * Recursion / size limits for {@see VariableReader::readVariables()}.
 *
 * `null` on any field means "no limit" — useful for full dumps. The defaults
 * here are the single-level shape used by inspector:trace --trace-var and
 * inspector:watch (depth 0, no children expanded). PeekVarCommand passes its
 * own options when the user requests a recursive output format.
 */
final class VariableReadOptions
{
    public function __construct(
        public readonly ?int $max_depth = 0,
        public readonly ?int $max_elements = 100,
        public readonly ?int $max_string = 200,
    ) {
    }

    public static function unlimited(): self
    {
        return new self(null, null, null);
    }
}
