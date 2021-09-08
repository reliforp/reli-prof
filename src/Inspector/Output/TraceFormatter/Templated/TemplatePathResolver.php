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

use Noodlehaus\Config;

use function assert;
use function is_string;

final class TemplatePathResolver implements TemplatePathResolverInterface
{
    public function __construct(
        private Config $config
    ) {
    }

    public function resolve(string $template_name): string
    {
        $path = $this->config->get('paths.templates');
        assert(is_string($path));
        return "{$path}/{$template_name}.php";
    }
}
