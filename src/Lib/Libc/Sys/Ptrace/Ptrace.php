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

namespace PhpProfiler\Lib\Libc\Sys\Ptrace;

use FFI\CData;
use PhpProfiler\Lib\Libc\Addressable;

interface Ptrace
{
    public function ptrace(
        PtraceRequest $request,
        int $pid,
        Addressable|CData|null|int $addr,
        Addressable|CData|null|int $data,
    ): int;
}
