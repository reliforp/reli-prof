<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Daemon\Reader\Context;

use Amp\Parallel\Context;
use Amp\Promise;
use PhpProfiler\Inspector\Settings\DaemonSettings;
use PhpProfiler\Inspector\Settings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings;

final class PhpReaderContext
{
    private Context\Context $context;

    public function __construct(Context\Context $context)
    {
        $this->context = $context;
    }

    public function start(): Promise
    {
        return $this->context->start();
    }

    /**
     * @param array{
     *     0: int,
     *     1: TargetPhpSettings,
     *     2: TraceLoopSettings,
     *     3: GetTraceSettings
     * } $array
     * @return Promise<int>
     */
    public function sendSettings(array $array): Promise
    {
        /** @var Promise<int> */
        return $this->context->send($array);
    }

    public function sendQuit(): Promise
    {
        /** @var Promise<int> */
        return $this->context->send(null);
    }

    public function isRunning(): bool
    {
        return $this->context->isRunning();
    }

    /**
    /* @return Promise<string>
     */
    public function receiveTrace(): Promise
    {
        /** @psalm-yield Promise<string> */
        return $this->context->receive();
    }
}
