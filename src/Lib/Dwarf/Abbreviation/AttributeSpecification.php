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

use PhpProfiler\Lib\Dwarf\Attribute;
use PhpProfiler\Lib\Dwarf\Form;

final class AttributeSpecification
{
    public function __construct(
        public Attribute $attribute,
        public Form $form,
        public ?int $implicit_const,
    ) {
    }
}