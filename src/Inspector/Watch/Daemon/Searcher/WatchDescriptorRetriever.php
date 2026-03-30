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

use Reli\Inspector\Daemon\Searcher\Worker\ProcessDescriptorCache;
use Reli\Inspector\Daemon\Searcher\Worker\ProcessDescriptorRetriever;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Retrieves WatchTargetDescriptor (with CG address) for watch daemon.
 *
 * Extends the base retriever to also resolve CG address.
 */
final class WatchDescriptorRetriever
{
    /** @var array<int, WatchTargetDescriptor> */
    private array $cache = [];

    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private PhpVersionDetector $php_version_detector,
    ) {
    }

    /**
     * @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>|'auto'> $target_php_settings
     */
    public function getDescriptor(
        int $pid,
        TargetPhpSettings $target_php_settings,
    ): WatchTargetDescriptor {
        if (isset($this->cache[$pid])) {
            return $this->cache[$pid];
        }

        try {
            $process_specifier = new ProcessSpecifier($pid);

            $decided = $this->php_version_detector->decidePhpVersion(
                $process_specifier,
                $target_php_settings,
            );

            $eg_address = $this->php_globals_finder
                ->findExecutorGlobals($process_specifier, $decided);

            $sg_address = $this->php_globals_finder
                ->findSAPIGlobals($process_specifier, $decided);

            $cg_address = $this->php_globals_finder
                ->findCompilerGlobals($process_specifier, $decided);
        } catch (\Throwable $e) {
            Log::debug('watch descriptor retrieval failed', [
                'pid' => $pid,
                'error' => $e->getMessage(),
            ]);
            $result = WatchTargetDescriptor::getInvalid();
            $this->cache[$pid] = $result;
            return $result;
        }

        /**
         * @psalm-var TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $decided
         */
        $result = new WatchTargetDescriptor(
            $pid,
            $eg_address,
            $sg_address,
            $cg_address,
            $decided->php_version,
        );
        $this->cache[$pid] = $result;
        return $result;
    }
}
