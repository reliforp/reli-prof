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

namespace Reli\Inspector\Watch\Daemon\Searcher;

use Mockery;
use Reli\BaseTestCase;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessSpecifier;

class WatchDescriptorRetrieverTest extends BaseTestCase
{
    public function testGetDescriptorSuccess(): void
    {
        $settings = new TargetPhpSettings(
            php_version: ZendTypeReader::V84,
        );
        $decided = new TargetPhpSettings(
            php_version: ZendTypeReader::V84,
        );

        $detector = Mockery::mock(PhpVersionDetector::class);
        $detector->shouldReceive('decidePhpVersion')
            ->once()
            ->andReturn($decided)
        ;

        $finder = Mockery::mock(PhpGlobalsFinder::class);
        $finder->shouldReceive('findExecutorGlobals')
            ->once()
            ->andReturn(0x1000)
        ;
        $finder->shouldReceive('findSAPIGlobals')
            ->once()
            ->andReturn(0x2000)
        ;
        $finder->shouldReceive('findCompilerGlobals')
            ->once()
            ->andReturn(0x3000)
        ;

        $retriever = new WatchDescriptorRetriever(
            $finder,
            $detector,
        );
        $desc = $retriever->getDescriptor(42, $settings);

        $this->assertSame(42, $desc->pid);
        $this->assertSame(0x1000, $desc->eg_address);
        $this->assertSame(0x2000, $desc->sg_address);
        $this->assertSame(0x3000, $desc->cg_address);
        $this->assertSame(ZendTypeReader::V84, $desc->php_version);
    }

    public function testGetDescriptorCachesResult(): void
    {
        $settings = new TargetPhpSettings(
            php_version: ZendTypeReader::V84,
        );
        $decided = new TargetPhpSettings(
            php_version: ZendTypeReader::V84,
        );

        $detector = Mockery::mock(PhpVersionDetector::class);
        $detector->shouldReceive('decidePhpVersion')
            ->once() // Only called once despite 2 getDescriptor calls
            ->andReturn($decided)
        ;

        $finder = Mockery::mock(PhpGlobalsFinder::class);
        $finder->shouldReceive('findExecutorGlobals')
            ->once()
            ->andReturn(0x100)
        ;
        $finder->shouldReceive('findSAPIGlobals')
            ->once()
            ->andReturn(0x200)
        ;
        $finder->shouldReceive('findCompilerGlobals')
            ->once()
            ->andReturn(0x300)
        ;

        $retriever = new WatchDescriptorRetriever(
            $finder,
            $detector,
        );

        $d1 = $retriever->getDescriptor(1, $settings);
        $d2 = $retriever->getDescriptor(1, $settings);
        $this->assertSame($d1, $d2); // Same instance from cache
    }

    public function testGetDescriptorReturnsInvalidOnError(): void
    {
        $settings = new TargetPhpSettings(
            php_version: ZendTypeReader::V84,
        );

        $detector = Mockery::mock(PhpVersionDetector::class);
        $detector->shouldReceive('decidePhpVersion')
            ->andThrow(new \RuntimeException('process gone'))
        ;

        $finder = Mockery::mock(PhpGlobalsFinder::class);

        $retriever = new WatchDescriptorRetriever(
            $finder,
            $detector,
        );
        $desc = $retriever->getDescriptor(999, $settings);

        $this->assertSame(0, $desc->pid);
    }
}
