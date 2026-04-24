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

use Reli\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;

interface PhpSearcherControllerInterface
{
    public function start(): void;

    /**
     * @param non-empty-string $regex
     * @param non-empty-string|null $thread_name_regex
     */
    public function sendTarget(
        string $regex,
        TargetPhpSettings $target_php_settings,
        int $pid,
        bool $no_cache = false,
        bool $needs_compiler_globals = false,
        ?string $thread_name_regex = null,
    ): void;

    public function receivePidList(): UpdateTargetProcessMessage;
}
