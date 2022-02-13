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

namespace PhpProfiler\Inspector\Settings\OutputSettings;

/**
 * @immutable
 */
final class OutputSettings
{
    public function __construct(
        public string $template_name,
        public ?string $output_path = null,
    ) {
    }
}
