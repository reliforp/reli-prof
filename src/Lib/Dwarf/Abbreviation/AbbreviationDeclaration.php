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

namespace PhpProfiler\Lib\Dwarf\Abbreviation;

use PhpProfiler\Lib\Dwarf\Tag;

final class AbbreviationDeclaration
{
    /** @param AttributeSpecification[] $attribute_specifications */
    public function __construct(
        public int $abbreviation_code,
        public Tag $tag,
        public bool $has_children,
        public array $attribute_specifications,
    ) {
    }
}
