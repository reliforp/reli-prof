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

namespace Reli\Inspector\Daemon\Searcher\Protocol\Message;

use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpInternals\ZendTypeReader;

final class TargetPhpSettingsMessage
{
    /**
     * @param non-empty-string $regex
     * @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>|'auto'> $target_php_settings
     * @param bool $needs_compiler_globals ask the searcher to resolve the
     *     CG address for each discovered PHP process. Set from the daemon
     *     when its feature set (e.g. inspector:trace --trace-var with
     *     static::/func_static:: scopes) requires CG. When false, the
     *     searcher skips the extra lookup and TargetProcessDescriptor's
     *     cg_address stays 0.
     */
    public function __construct(
        public string $regex,
        public TargetPhpSettings $target_php_settings,
        public int $parent_pid,
        public bool $no_cache = false,
        public bool $needs_compiler_globals = false,
    ) {
    }
}
