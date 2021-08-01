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

use PhpProfiler\Inspector\Output\TraceFormatter\CallTraceFormatter;
use PhpProfiler\Lib\PhpProcessReader\CallTrace;

final class TemplatedCallTraceFormatter implements CallTraceFormatter
{
    private TemplatePathResolverInterface $template_path_resolver;
    private string $template;

    public function __construct(
        TemplatePathResolverInterface $template_path_resolver,
        string $template
    ) {
        $this->template_path_resolver = $template_path_resolver;
        $this->template = $template;
    }

    public function format(CallTrace $call_trace): string
    {
        ob_start();
        /** @psalm-suppress UnresolvableInclude */
        include $this->template_path_resolver->resolve($this->template);
        $buffer = ob_get_clean();
        assert(is_string($buffer));
        return $buffer;
    }
}