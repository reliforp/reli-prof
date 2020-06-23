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

namespace PhpProfiler\Inspector\Daemon\Searcher\Context;

use Amp\Parallel\Context;

final class PhpSearcherContextCreator
{
    public function create(): Context\Context
    {
        return Context\create(__DIR__ . '/php-searcher.php');
    }
}
