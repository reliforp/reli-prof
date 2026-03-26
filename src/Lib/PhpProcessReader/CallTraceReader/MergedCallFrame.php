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
final class MergedCallFrame
{
    private function __construct(
        public readonly ?CallFrame $phpFrame,
        public readonly ?NativeCallFrame $nativeFrame,
    ) {
    }

    public static function fromPhp(CallFrame $frame): self
    {
        return new self($frame, null);
    }

    public static function fromNative(NativeCallFrame $frame): self
    {
        return new self(null, $frame);
    }

    public function isNative(): bool
    {
        return $this->nativeFrame !== null;
    }

    public function isPhp(): bool
    {
        return $this->phpFrame !== null;
    }
}
