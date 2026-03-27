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

namespace Reli\Inspector\Settings\PhpSpySettings;

final class PhpSpySettings
{
    public const DEFAULT_SLEEP_US = 10000;
    public const DEFAULT_BUFFER_SIZE = 4096;
    public const DEFAULT_RATE_HZ = 99;

    public function __construct(
        public ?string $phpspy_path = null,
        public int $sleep_us = self::DEFAULT_SLEEP_US,
        public int $buffer_size = self::DEFAULT_BUFFER_SIZE,
        public int $rate_hz = self::DEFAULT_RATE_HZ,
        public ?string $extra_args = null,
    ) {
    }
}
