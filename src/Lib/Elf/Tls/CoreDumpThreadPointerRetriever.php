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

namespace Reli\Lib\Elf\Tls;

use Reli\Lib\Elf\Structure\Elf64\Elf64PrStatus;

final class CoreDumpThreadPointerRetriever implements ThreadPointerRetrieverInterface
{
    /** @param Elf64PrStatus[] $threads */
    public function __construct(
        private array $threads,
    ) {
    }

    #[\Override]
    public function getThreadPointer(int $pid): int
    {
        foreach ($this->threads as $thread) {
            if ($thread->pid === $pid && $thread->fs_base !== null) {
                return $thread->fs_base;
            }
        }
        // Fall back to the first thread with a valid fs_base
        foreach ($this->threads as $thread) {
            if ($thread->fs_base !== null && $thread->fs_base !== 0) {
                return $thread->fs_base;
            }
        }
        throw new TlsFinderException(
            'cannot find thread pointer from coredump (no fs_base in NT_PRSTATUS)'
        );
    }
}
