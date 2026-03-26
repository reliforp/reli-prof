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

namespace Reli\Lib\PhpProcessReader\CallTraceReader;

/** @psalm-immutable */
final class NativeCallFrame
{
    public function __construct(
        public readonly string $symbol_name,
        public readonly string $module_name,
        public readonly int $offset,
        public readonly int $ip,
    ) {
    }

    public function getDisplayName(): string
    {
        $name = $this->symbol_name;
        if ($this->offset > 0) {
            $name .= sprintf('+0x%x', $this->offset);
        }
        return $name;
    }
}
