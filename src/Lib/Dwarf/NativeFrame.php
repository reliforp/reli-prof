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

namespace Reli\Lib\Dwarf;

/** @psalm-immutable */
final class NativeFrame
{
    public function __construct(
        public readonly int $ip,
        public readonly ?string $symbolName,
        public readonly string $moduleName,
        public readonly int $offset,
    ) {
    }

    public function getDisplayName(): string
    {
        $name = $this->symbolName ?? sprintf('0x%x', $this->ip);
        if ($this->offset > 0 && $this->symbolName !== null) {
            $name .= sprintf('+0x%x', $this->offset);
        }
        return $name;
    }
}
