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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext;

final class ScalarValueContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public bool|int|float|null|array $value,
    ) {
        if (is_float($value)) {
            if (\is_infinite($value)) {
                $this->value = ['infinity'];
            } elseif (\is_nan($value)) {
                $this->value = ['nan'];
            }
        }
    }

    #[\Override]
    public function getContexts(): iterable
    {
        // The binary sink stores attribute values with a plain string
        // cast, which folds true/1 to "1" and false to "". Record the
        // type separately so exporters can reconstruct it exactly.
        return [
            'value' => $this->value,
            'value_type' => match (true) {
                is_bool($this->value) => 'bool',
                is_int($this->value) => 'int',
                // inf/nan floats are normalized to ['infinity'] / ['nan']
                is_float($this->value), is_array($this->value) => 'float',
                default => 'null',
            },
        ];
    }
}
