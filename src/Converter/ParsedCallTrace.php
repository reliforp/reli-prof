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

namespace Reli\Converter;

/** @psalm-immutable */
final class ParsedCallTrace
{
    /** @var ParsedCallFrame[] */
    public array $call_frames;

    public function __construct(
        ParsedCallFrame ...$call_frames
    ) {
        $this->call_frames = $call_frames;
    }
}
