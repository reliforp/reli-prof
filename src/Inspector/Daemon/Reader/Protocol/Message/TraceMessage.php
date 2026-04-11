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

namespace Reli\Inspector\Daemon\Reader\Protocol\Message;

use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

final class TraceMessage
{
    /**
     * @param array<string, string>|null $annotations optional per-sample
     *     key/value metadata (e.g. `--trace-var` peek results). Populated
     *     by the reader worker when the daemon is launched with any
     *     annotation-producing feature; ignored otherwise. Serialises
     *     over the existing AMPHP IPC channel without additional work
     *     because arrays of strings are natively supported.
     */
    public function __construct(
        public CallTrace $trace,
        public ?int $pid = null,
        public ?array $annotations = null,
    ) {
    }
}
