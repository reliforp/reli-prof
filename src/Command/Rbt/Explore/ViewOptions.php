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

namespace Reli\Command\Rbt\Explore;

/**
 * Read-only filter knobs that influence every aggregator query.
 *
 * - $no_line       Group frames by function name only (drop file:line).
 * - $match_re      Whole-stack inclusion filter (PCRE with delimiters).
 *                  Samples whose stack contains no matching frame are
 *                  dropped before aggregation.
 */
final class ViewOptions
{
    public function __construct(
        public readonly bool $no_line = false,
        public readonly ?string $match_re = null,
    ) {
    }

    public function withNoLine(bool $no_line): self
    {
        return new self($no_line, $this->match_re);
    }

    public function withMatch(?string $match_re): self
    {
        return new self($this->no_line, $match_re);
    }
}
