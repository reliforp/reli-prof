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

namespace PhpProfiler\Lib\Amphp;

use Amp\Parallel\Context;

final class ContextCreator implements ContextCreatorInterface
{
    public const ENTRY_SCRIPT = __DIR__ . '/context-entry.php';

    private string $di_config_file;

    public function __construct(string $di_config_file)
    {
        $this->di_config_file = $di_config_file;
    }

    /**
     * @param class-string<ContextEntryPointInterface> $entry_point_name
     */
    public function create(string $entry_point_name): Context\Context
    {
        return Context\create([
            self::ENTRY_SCRIPT,
            $entry_point_name,
            $this->di_config_file,
        ]);
    }
}
