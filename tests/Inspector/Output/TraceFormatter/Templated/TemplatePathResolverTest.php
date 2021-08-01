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

use Mockery;
use Noodlehaus\Config;
use PHPUnit\Framework\TestCase;

class TemplatePathResolverTest extends TestCase
{
    public function testResolve(): void
    {
        $config = Mockery::mock(Config::class);
        $config->expects()->get('paths.templates')->andReturns('test_path');
        $resolver = new TemplatePathResolver($config);
        $this->assertSame('test_path/test.php', $resolver->resolve('test'));
    }
}
