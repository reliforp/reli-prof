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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext;

final class CallFramesContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function getFrameAt(int $frame_no): CallFrameContext
    {
        /** @var CallFrameContext */
        return $this->referencing_contexts[(string)$frame_no];
    }

    public function getFrameCount(): int
    {
        return count($this->referencing_contexts);
    }

    public function getContexts(): iterable
    {
        return [
            '#count' => count($this->referencing_contexts),
        ];
    }
}
