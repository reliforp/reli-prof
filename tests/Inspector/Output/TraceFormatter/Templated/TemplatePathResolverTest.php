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

use Mockery;
use Noodlehaus\Config;
use Reli\BaseTestCase;

class TemplatePathResolverTest extends BaseTestCase
{
    public function testResolve(): void
    {
        $config = Mockery::mock(Config::class);
        $config->expects()->get('paths.templates')->andReturns('test_path');
        $resolver = new TemplatePathResolver($config);
        $this->assertSame('test_path/test.php', $resolver->resolve('test'));
    }
}
