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

namespace PhpProfiler\Lib\Loop\LoopMiddleware;

use PhpProfiler\Lib\Console\EchoBackCanceller;
use PhpProfiler\Lib\Loop\LoopMiddlewareInterface;

final class KeyboardCancelMiddleware implements LoopMiddlewareInterface
{
    private LoopMiddlewareInterface $chain;
    private string $cancel_key;
    /** @var resource */
    private $keyboard_input;
    private EchoBackCanceller $echo_back_canceller;

    public function __construct(
        string $cancel_key,
        EchoBackCanceller $echo_back_canceller,
        LoopMiddlewareInterface $chain
    ) {
        $this->chain = $chain;
        $this->keyboard_input = fopen('php://stdin', 'r');
        stream_set_blocking($this->keyboard_input, false);
        $this->cancel_key = $cancel_key;
        $this->echo_back_canceller = $echo_back_canceller;
    }

    public function invoke(): bool
    {
        $key = fread($this->keyboard_input, 1);
        if ($key === $this->cancel_key) {
            return false;
        }
        return $this->chain->invoke();
    }
}
