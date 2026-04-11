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

use Closure;
use Reli\Inspector\Output\TraceFormatter\CallTraceFormatter;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

use function assert;
use function file_get_contents;
use function is_string;
use function ob_get_clean;
use function ob_start;

final class TemplatedCallTraceFormatter implements CallTraceFormatter
{
    /** @var ?Closure(CallTrace, ?array<string, string>): string */
    private ?Closure $renderer = null;

    public function __construct(
        private TemplatePathResolverInterface $template_path_resolver,
        private string $template
    ) {
    }

    #[\Override]
    public function format(CallTrace $call_trace, ?array $annotations = null): string
    {
        return ($this->getRenderer())($call_trace, $annotations);
    }

    /** @return Closure(CallTrace, ?array<string, string>): string */
    private function getRenderer(): Closure
    {
        if ($this->renderer === null) {
            $template_path = $this->template_path_resolver->resolve($this->template);
            $template_content = file_get_contents($template_path);
            assert(is_string($template_content));
            // Strip the PHP header (declare/use/docblock) before the first
            // closing PHP tag, so it can be embedded in a closure
            $closing_tag = '?' . '>';
            $body_start = strpos($template_content, $closing_tag);
            if ($body_start !== false) {
                $template_body = substr($template_content, $body_start + 2);
                /**
                 * @psalm-suppress ForbiddenCode
                 * @var Closure(CallTrace, ?array<string, string>): string $renderer
                 */
                $renderer = eval(
                    'return static function ('
                    . '\\' . CallTrace::class . ' $call_trace, '
                    . '?array $annotations = null'
                    . '): string {'
                    . 'ob_start();'
                    . '?' . '>' . $template_body
                    . '<' . '?php return ob_get_clean();'
                    . '};'
                );
            } else {
                // Template is pure PHP (no closing tag separator) — fall back to include
                $renderer = static function (
                    CallTrace $call_trace,
                    ?array $annotations = null,
                ) use ($template_path): string {
                    ob_start();
                    /** @psalm-suppress UnresolvableInclude */
                    include $template_path;
                    $buffer = ob_get_clean();
                    assert(is_string($buffer));
                    return $buffer;
                };
            }
            $this->renderer = $renderer;
        }
        return $this->renderer;
    }
}
