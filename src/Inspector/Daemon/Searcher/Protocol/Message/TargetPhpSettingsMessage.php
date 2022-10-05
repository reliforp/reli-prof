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

namespace PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message;

use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;

final class TargetPhpSettingsMessage
{
    /** @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>|'auto'> $target_php_settings */
    public function __construct(
        public string $regex,
        public TargetPhpSettings $target_php_settings,
    ) {
    }
}
