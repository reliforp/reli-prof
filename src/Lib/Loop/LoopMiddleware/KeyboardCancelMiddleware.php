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

namespace Reli\Lib\Loop\LoopMiddleware;

use Reli\Lib\Console\EchoBackCanceller;
use Reli\Lib\Loop\LoopMiddlewareInterface;

final class KeyboardCancelMiddleware implements LoopMiddlewareInterface
{
    /** @var resource */
    private $keyboard_input;

    public function __construct(
        private string $cancel_key,
        private EchoBackCanceller $echo_back_canceller,
        private LoopMiddlewareInterface $chain
    ) {
        $this->keyboard_input = fopen('php://stdin', 'r');
        stream_set_blocking($this->keyboard_input, false);
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
