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

namespace Reli\Converter\BinaryTrace;

use Reli\Converter\ParsedCallTrace;

/** @psalm-immutable */
final class BinaryTraceSample
{
    public function __construct(
        public ParsedCallTrace $trace,
        public ?int $timestamp_delta_us = null,
        public ?int $accumulated_timestamp_us = null,
    ) {
    }
}
