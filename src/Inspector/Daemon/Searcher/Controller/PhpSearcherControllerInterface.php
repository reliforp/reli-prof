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

namespace Reli\Inspector\Daemon\Searcher\Controller;

use Amp\Promise;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;

interface PhpSearcherControllerInterface
{
    /** @return Promise<null> */
    public function start(): Promise;

    /** @return Promise<int> */
    public function sendTarget(string $regex, TargetPhpSettings $target_php_settings, int $pid): Promise;

    /** @return Promise<UpdateTargetProcessMessage> */
    public function receivePidList(): Promise;
}
