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

namespace PhpProfiler\Inspector\Output\TraceFormatter\Templated;

use PhpProfiler\Inspector\Output\TraceFormatter\CallTraceFormatter;
use PhpProfiler\Inspector\Settings\OutputSettings\OutputSettings;

class TraceFormatterFactory
{
    /** @var array<string, TemplatedCallTraceFormatter> */
    private array $cache = [];

    public function __construct(
        private TemplatePathResolverInterface $template_path_resolver
    ) {
    }

    public function createFromSettings(OutputSettings $settings): CallTraceFormatter
    {
        if (!isset($this->cache[$settings->template_name])) {
            $this->cache[$settings->template_name] = new TemplatedCallTraceFormatter(
                $this->template_path_resolver,
                $settings->template_name
            );
        }
        return $this->cache[$settings->template_name];
    }
}
