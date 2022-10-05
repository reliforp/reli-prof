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

namespace PhpProfiler\Lib\Defer;

function defer(?ScopeGuard &$scope_guard, callable $function): void
{
    $scope_guard = new ScopeGuard(
        \Closure::fromCallable($function),
        $scope_guard ?? null
    );
}
