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

namespace PhpProfiler\Inspector\Output\TraceFormatter\Templated;

use PhpProfiler\Inspector\Settings\TemplatedTraceFormatterSettings\TemplateSettings;

final class TemplatedTraceFormatterFactory
{
    private TemplatePathResolverInterface $template_path_resolver;
    /** @var array<string, TemplatedCallTraceFormatter> */
    private array $cache = [];

    public function __construct(
        TemplatePathResolverInterface $template_path_resolver
    ) {
        $this->template_path_resolver = $template_path_resolver;
    }

    public function createFromSettings(TemplateSettings $settings): TemplatedCallTraceFormatter
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
