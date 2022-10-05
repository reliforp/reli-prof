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

namespace Reli\Lib\PhpProcessReader;

/** @psalm-immutable */
final class CallTrace
{
    /** @var CallFrame[] */
    public array $call_frames;

    public function __construct(
        CallFrame ...$call_frames
    ) {
        $this->call_frames = $call_frames;
    }
}
