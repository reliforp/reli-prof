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
use PhpProfiler\Inspector\Settings\OutputSettings\OutputSettings;
use PHPUnit\Framework\TestCase;

class TraceFormatterFactoryTest extends TestCase
{
    public function testCreateFromSettingsCachedByName(): void
    {
        $path_resolver = Mockery::mock(TemplatePathResolverInterface::class);
        $factory = new TraceFormatterFactory($path_resolver);
        $settings1 = $factory->createFromSettings(
            new OutputSettings(
                'test1',
                null,
            )
        );
        $this->assertSame(
            $settings1,
            $factory->createFromSettings(
                new OutputSettings(
                    'test1',
                    null,
                )
            )
        );
        $this->assertNotSame(
            $settings1,
            $factory->createFromSettings(
                new OutputSettings(
                    'test2',
                    null,
                )
            )
        );
    }
}
