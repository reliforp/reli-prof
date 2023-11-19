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
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessSpecifier;
use PHPUnit\Framework\TestCase;

class ProcessDescriptorRetrieverTest extends BaseTestCase
{
    public function testGetProcessDescriptorCached(): void
    {
        $php_version_detector = \Mockery::mock(PhpVersionDetector::class);
        $php_globals_finder = \Mockery::mock(PhpGlobalsFinder::class);
        $process_descriptor_retriever = new ProcessDescriptorRetriever(
            $php_globals_finder,
            $php_version_detector,
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
        $process_descriptor_retriever = new ProcessDescriptorRetriever(
            $php_globals_finder,
            $php_version_detector,
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

        $process_descriptor_retriever = new ProcessDescriptorRetriever(
            $php_globals_finder,
            $php_version_detector,
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
}
