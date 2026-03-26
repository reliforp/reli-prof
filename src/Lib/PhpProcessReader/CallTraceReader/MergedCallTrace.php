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
final class MergedCallTrace
{
    /** @var MergedCallFrame[] */
    public readonly array $frames;

    public function __construct(MergedCallFrame ...$frames)
    {
        $this->frames = $frames;
    }

    public function getPhpOnlyTrace(): CallTrace
    {
        $php_frames = [];
        foreach ($this->frames as $frame) {
            if ($frame->phpFrame !== null) {
                $php_frames[] = $frame->phpFrame;
            }
        }
        return new CallTrace(...$php_frames);
    }
}
