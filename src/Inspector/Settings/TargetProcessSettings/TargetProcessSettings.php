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

namespace PhpProfiler\Inspector\Settings\TargetProcessSettings;

final class TargetProcessSettings
{
    /** @param list<string> $arguments */
    public function __construct(
        public ?int $pid,
        public ?string $command = null,
        public array $arguments = [],
    ) {
    }
}
