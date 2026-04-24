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

namespace Reli\Inspector\Daemon\Searcher\Worker;

use Hamcrest\Matchers;
use Reli\BaseTestCase;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\EgActivityChecker;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessSpecifier;

class ProcessDescriptorRetrieverTest extends BaseTestCase
{
    public function testGetProcessDescriptorCached(): void
    {
        $php_version_detector = \Mockery::mock(PhpVersionDetector::class);
        $php_globals_finder = \Mockery::mock(PhpGlobalsFinder::class);
        $eg_activity_checker = \Mockery::mock(EgActivityChecker::class);
        $process_descriptor_retriever = new ProcessDescriptorRetriever(
            $php_globals_finder,
            $php_version_detector,
            $eg_activity_checker,
        );
        $cache = new ProcessDescriptorCache();
        $cache->set(
            new TargetProcessDescriptor(1, 42, 0, ZendTypeReader::V80)
        );
        $result = $process_descriptor_retriever->getProcessDescriptor(
            1,
            new TargetPhpSettings(),
            $cache,
        );
        $this->assertEquals(
            new TargetProcessDescriptor(1, 42, 0, ZendTypeReader::V80),
            $result
        );
    }

    public function testGetProcessDescriptorNotCached(): void
    {
        $target_php_settings = new TargetPhpSettings(php_version: ZendTypeReader::V80);
        $php_version_detector = \Mockery::mock(PhpVersionDetector::class);
        $php_version_detector->expects()
            ->decidePhpVersion(
                Matchers::equalTo(new ProcessSpecifier(1)),
                $target_php_settings,
            )
            ->andReturns(
                $target_php_settings
            )
        ;
        $php_globals_finder = \Mockery::mock(PhpGlobalsFinder::class);
        $php_globals_finder->expects()
            ->findExecutorGlobals(
                Matchers::equalTo(new ProcessSpecifier(1)),
                $target_php_settings
            )
            ->andReturns(42)
        ;
        $php_globals_finder->expects()
            ->findSAPIGlobals(
                Matchers::equalTo(new ProcessSpecifier(1)),
                $target_php_settings
            )
            ->andReturns(42)
        ;
        $eg_activity_checker = \Mockery::mock(EgActivityChecker::class);
        $eg_activity_checker->expects()
            ->isActive(1, 42, ZendTypeReader::V80)
            ->andReturnTrue()
        ;
        $process_descriptor_retriever = new ProcessDescriptorRetriever(
            $php_globals_finder,
            $php_version_detector,
            $eg_activity_checker,
        );
        $cache = new ProcessDescriptorCache();
        $result = $process_descriptor_retriever->getProcessDescriptor(
            1,
            $target_php_settings,
            $cache,
        );
        $this->assertEquals(
            new TargetProcessDescriptor(1, 42, 42, ZendTypeReader::V80),
            $result
        );
        $this->assertEquals(
            new TargetProcessDescriptor(1, 42, 42, ZendTypeReader::V80),
            $cache->get(1)
        );
    }

    public function testGetProcessDescriptorFailed(): void
    {
        $target_php_settings = new TargetPhpSettings(php_version: ZendTypeReader::V80);
        $php_version_detector = \Mockery::mock(PhpVersionDetector::class);
        $php_globals_finder = \Mockery::mock(PhpGlobalsFinder::class);
        $eg_activity_checker = \Mockery::mock(EgActivityChecker::class);

        $process_descriptor_retriever = new ProcessDescriptorRetriever(
            $php_globals_finder,
            $php_version_detector,
            $eg_activity_checker,
        );
        $php_version_detector->expects()
            ->decidePhpVersion(
                Matchers::equalTo(new ProcessSpecifier(1)),
                $target_php_settings
            )
            ->andThrow(new \Exception())
        ;
        $cache = new ProcessDescriptorCache();
        $result = $process_descriptor_retriever->getProcessDescriptor(1, $target_php_settings, $cache);
        $this->assertSame(TargetProcessDescriptor::getInvalid(), $result);
        $this->assertSame(TargetProcessDescriptor::getInvalid(), $cache->get(1));
    }

    public function testGetProcessDescriptorDefersWhenEgInactive(): void
    {
        $target_php_settings = new TargetPhpSettings(php_version: ZendTypeReader::V80);
        $php_version_detector = \Mockery::mock(PhpVersionDetector::class);
        $php_version_detector->expects()
            ->decidePhpVersion(
                Matchers::equalTo(new ProcessSpecifier(1)),
                $target_php_settings,
            )
            ->andReturns($target_php_settings)
            ->once()
        ;
        $php_globals_finder = \Mockery::mock(PhpGlobalsFinder::class);
        $php_globals_finder->expects()
            ->findExecutorGlobals(
                Matchers::equalTo(new ProcessSpecifier(1)),
                $target_php_settings,
            )
            ->andReturns(42)
            ->once()
        ;
        $php_globals_finder->expects()
            ->findSAPIGlobals(
                Matchers::equalTo(new ProcessSpecifier(1)),
                $target_php_settings,
            )
            ->andReturns(84)
            ->once()
        ;
        $eg_activity_checker = \Mockery::mock(EgActivityChecker::class);
        $eg_activity_checker->expects()
            ->isActive(1, 42, ZendTypeReader::V80)
            ->andReturnFalse()
            ->once()
        ;

        $process_descriptor_retriever = new ProcessDescriptorRetriever(
            $php_globals_finder,
            $php_version_detector,
            $eg_activity_checker,
        );
        $cache = new ProcessDescriptorCache();

        // First call: inactive -> stored in pending cache, invalid returned
        $first = $process_descriptor_retriever->getProcessDescriptor(1, $target_php_settings, $cache);
        $this->assertSame(TargetProcessDescriptor::getInvalid(), $first);
        $this->assertNull($cache->get(1));
        $this->assertEquals(
            new TargetProcessDescriptor(1, 42, 84, ZendTypeReader::V80),
            $cache->getPending(1),
        );

        // Second call: active -> pending promoted to valid without
        // re-running findExecutorGlobals / findSAPIGlobals / decidePhpVersion
        $eg_activity_checker->expects()
            ->isActive(1, 42, ZendTypeReader::V80)
            ->andReturnTrue()
            ->once()
        ;
        $second = $process_descriptor_retriever->getProcessDescriptor(1, $target_php_settings, $cache);
        $this->assertEquals(
            new TargetProcessDescriptor(1, 42, 84, ZendTypeReader::V80),
            $second,
        );
        $this->assertEquals(
            new TargetProcessDescriptor(1, 42, 84, ZendTypeReader::V80),
            $cache->get(1),
        );
        $this->assertNull($cache->getPending(1));
    }

    public function testGetProcessDescriptorStaysPendingWhenEgStillInactive(): void
    {
        $target_php_settings = new TargetPhpSettings(php_version: ZendTypeReader::V80);
        $php_version_detector = \Mockery::mock(PhpVersionDetector::class);
        $php_version_detector->expects()
            ->decidePhpVersion(
                Matchers::equalTo(new ProcessSpecifier(1)),
                $target_php_settings,
            )
            ->andReturns($target_php_settings)
            ->once()
        ;
        $php_globals_finder = \Mockery::mock(PhpGlobalsFinder::class);
        $php_globals_finder->expects()
            ->findExecutorGlobals(
                Matchers::equalTo(new ProcessSpecifier(1)),
                $target_php_settings,
            )
            ->andReturns(42)
            ->once()
        ;
        $php_globals_finder->expects()
            ->findSAPIGlobals(
                Matchers::equalTo(new ProcessSpecifier(1)),
                $target_php_settings,
            )
            ->andReturns(84)
            ->once()
        ;
        $eg_activity_checker = \Mockery::mock(EgActivityChecker::class);
        $eg_activity_checker->expects()
            ->isActive(1, 42, ZendTypeReader::V80)
            ->andReturnFalse()
            ->twice()
        ;

        $process_descriptor_retriever = new ProcessDescriptorRetriever(
            $php_globals_finder,
            $php_version_detector,
            $eg_activity_checker,
        );
        $cache = new ProcessDescriptorCache();

        $first = $process_descriptor_retriever->getProcessDescriptor(1, $target_php_settings, $cache);
        $this->assertSame(TargetProcessDescriptor::getInvalid(), $first);

        // Pending still pending, no re-analysis
        $second = $process_descriptor_retriever->getProcessDescriptor(1, $target_php_settings, $cache);
        $this->assertSame(TargetProcessDescriptor::getInvalid(), $second);
        $this->assertNull($cache->get(1));
        $this->assertEquals(
            new TargetProcessDescriptor(1, 42, 84, ZendTypeReader::V80),
            $cache->getPending(1),
        );
    }
}
