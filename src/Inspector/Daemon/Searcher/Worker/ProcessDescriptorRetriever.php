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

use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessSpecifier;

class ProcessDescriptorRetriever
{
    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private PhpVersionDetector $php_version_detector,
    ) {
    }

    /** @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>|'auto'> $target_php_settings */
    public function getProcessDescriptor(
        int $pid,
        TargetPhpSettings $target_php_settings,
        ProcessDescriptorCache $process_descriptor_cache,
    ): TargetProcessDescriptor {
        $cache = $process_descriptor_cache->get($pid);
        if (!is_null($cache)) {
            return $cache;
        }

        try {
            $process_specifier = new ProcessSpecifier($pid);
            Log::debug('deciding php version', ['target_pid' => $pid]);
            $target_php_settings_decided = $this->php_version_detector->decidePhpVersion(
                $process_specifier,
                $target_php_settings
            );
            Log::debug('php version decided', ['target_pid' => $pid]);
            $eg_address = $this->php_globals_finder->findExecutorGlobals(
                new ProcessSpecifier($pid),
                $target_php_settings_decided,
            );
            $sg_address = $this->php_globals_finder->findSAPIGlobals(
                $process_specifier,
                $target_php_settings_decided
            );
        } catch (\Throwable $e) {
            Log::debug(
                'error on analyzing php binary',
                [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTrace()
                ]
            );
            $process_descriptor_cache->setInvalid($pid);
            return TargetProcessDescriptor::getInvalid();
        }

        Log::debug('successfully analyzed php binary', [$pid]);
        /** @psalm-var TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings_decided */

        $result = new TargetProcessDescriptor(
            $pid,
            $eg_address,
            $sg_address,
            $target_php_settings_decided->php_version,
        );
        $process_descriptor_cache->set($result);
        return $result;
    }
}
