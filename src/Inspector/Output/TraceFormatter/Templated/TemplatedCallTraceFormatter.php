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
use Reli\Lib\PhpProcessReader\CallTrace;

use function assert;
use function is_string;
use function ob_get_clean;
use function ob_start;

final class TemplatedCallTraceFormatter implements CallTraceFormatter
{
    public function __construct(
        private TemplatePathResolverInterface $template_path_resolver,
        private string $template
    ) {
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
