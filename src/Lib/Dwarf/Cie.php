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

final class Cie
{
    public function __construct(
        public readonly int $codeAlignmentFactor,
        public readonly int $dataAlignmentFactor,
        public readonly int $returnAddressRegister,
        public readonly string $initialInstructions,
        public readonly string $augmentation,
        public readonly int $fdeEncoding,
        public readonly ?int $lsdaEncoding,
        public readonly ?int $personalityEncoding,
        public readonly ?int $personalityAddress,
    ) {
    }
}
