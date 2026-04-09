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

namespace Reli\Inspector\Output\TraceFormatter\Templated;

use Reli\Inspector\Output\TraceFormatter\CallTraceFormatter;
use Reli\Inspector\Settings\OutputSettings\OutputSettings;

/** @psalm-suppress ClassMustBeFinal */
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
        $template_name = $settings->getTemplateName();
        if (!isset($this->cache[$template_name])) {
            $this->cache[$template_name] = new TemplatedCallTraceFormatter(
                $this->template_path_resolver,
                $template_name
            );
        }
        return $this->cache[$template_name];
    }
}
