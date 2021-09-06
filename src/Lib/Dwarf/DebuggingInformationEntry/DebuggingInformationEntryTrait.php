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

namespace PhpProfiler\Lib\Dwarf\DebuggingInformationEntry;

use PhpProfiler\Lib\Dwarf\Attribute;
use PhpProfiler\Lib\Dwarf\Tag;

trait DebuggingInformationEntryTrait
{
    /**
     * @param Attribute[] $attributes
     * @param DebuggingInformationEntry[] $children
     */
    public function __construct(
        public int $abbreviation_code,
        public Tag $tag,
        public array $attributes,
        public array $children,
    ) {
    }
}
