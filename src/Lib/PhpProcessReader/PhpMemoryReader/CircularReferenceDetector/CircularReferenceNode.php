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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\CircularReferenceDetector;

final class CircularReferenceNode
{
    public function __construct(
        public readonly string $context_type,
        public readonly string $link_name,
        public readonly int $address,
        public readonly int $size,
        public readonly ?string $class_name,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            'context_type' => $this->context_type,
            'link_name' => $this->link_name,
            'address' => sprintf('0x%x', $this->address),
            'size' => $this->size,
        ];
        if ($this->class_name !== null) {
            $result['class_name'] = $this->class_name;
        }
        return $result;
    }
}
