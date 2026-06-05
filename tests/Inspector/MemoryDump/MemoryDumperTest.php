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

namespace Reli\Inspector\MemoryDump;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use Reli\Lib\PhpProcessReader\MainExecutable\ProcExeReadlinkResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Reli\BaseTestCase;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprintCreator;
use Reli\Lib\Elf\Process\LinkMapLoader;
use Reli\Lib\Elf\Process\PerBinarySymbolCacheRetriever;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use Reli\Lib\File\CatFileReader;
use Reli\Lib\File\PathResolver\ContainerAwarePathResolver;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpSymbolReaderCreator;
use Reli\Lib\PhpProcessReader\PhpTsrmLsCacheFinder;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\PhpProcessReader\TsrmGlobalsResolver;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\TargetPhpVmProvider;

#[Group('target-version')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class MemoryDumperTest extends BaseTestCase
{
    /** @var resource|null */
    private $child = null;

    protected function tearDown(): void
    {
        if (!is_null($this->child)) {
            $child_status = proc_get_status($this->child);
            if (is_array($child_status)) {
                if ($child_status['running']) {
                    posix_kill($child_status['pid'], SIGKILL);
                }
            }
        }
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testDump(
        string $php_version,
        string $docker_image_name,
    ): void {
        $memory_reader = new MemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();
        $process_memory_map_creator = ProcessMemoryMapCreator::create();

        $target_script = <<<'CODE'
            <?php
            $data = str_repeat("x", 1024 * 512);
            fputs(STDOUT, "ready\n");
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );

        [$ready, $seen] = TargetPhpVmProvider::waitForMarkerLine($pipes[1], 'ready');
        $this->assertTrue($ready, "child did not print 'ready'. Got: " . var_export($seen, true));

        $globals_finder = $this->createGlobalsFinder(
            $memory_reader,
            $zend_type_reader_creator,
        );
        $process = new ProcessSpecifier($pid);
        $settings = new TargetPhpSettings(php_version: $php_version);

        $eg = $globals_finder->findExecutorGlobals($process, $settings);
        $cg = $globals_finder->findCompilerGlobals($process, $settings);
        $bg = $globals_finder->findBasicGlobals($process, $settings);
        $module_registry = $globals_finder->findModuleRegistry($process, $settings);
        $tsrm_ls_cache = $globals_finder->findTsrmLsCache($process, $settings);

        $dumper = new MemoryDumper(
            $memory_reader,
            $zend_type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                $process_memory_map_creator,
                $zend_type_reader_creator,
            ),
            $process_memory_map_creator,
        );

        $output_path = tempnam(
            sys_get_temp_dir(),
            'reli-dump-test-',
        );
        $this->assertNotFalse($output_path);

        $result = $dumper->dump(
            $process,
            $settings,
            $eg,
            $cg,
            $output_path,
            bg_address: $bg,
            module_registry_address: $module_registry,
            tsrm_ls_cache_address: $tsrm_ls_cache,
        );

        $this->assertFileExists($result->output_path);
        $this->assertGreaterThan(0, $result->region_count);
        $this->assertGreaterThan(
            512 * 1024,
            $result->total_bytes,
            'Dump should contain at least 512K of data',
        );

        // Regression: when bg_address is resolved, both the v3 header
        // *and* the bytes themselves must land in the dump so
        // EmitModulesJob can deref php_basic_globals offline. The
        // failure mode this guards against is silent: the analyzer
        // would see a non-null address, deref garbage, and skip the
        // shutdown-function walk for reasons unrelated to v3 parsing.
        if ($bg !== null) {
            $factory = new MemoryDumpReaderFactory(new \DI\ContainerBuilder());
            $parsed = self::reflectParse($factory, $output_path);
            $this->assertArrayHasKey(
                'basic_globals',
                $parsed['module_globals'],
                'v3 header must carry bg_address when resolved',
            );
            $this->assertSame($bg, $parsed['module_globals']['basic_globals']);

            $bg_size = (new ZendTypeReaderCreator())
                ->create($php_version)
                ->sizeOf('php_basic_globals');
            $covered = false;
            foreach ($parsed['region_index'] as $region) {
                $end = $region['address'] + $region['size'];
                if (
                    $bg >= $region['address']
                    && ($bg + $bg_size) <= $end
                ) {
                    $covered = true;
                    break;
                }
            }
            $this->assertTrue(
                $covered,
                'php_basic_globals interval must land in a captured region',
            );
        }

        // Regression: module_registry (and, on ZTS, tsrm_ls_cache) must be
        // written into the dump header so the offline analyze path can resolve
        // ext/phar's per-thread module globals without a live process. Without
        // this the phar walker silently does nothing on a dump.
        $factory = $factory ?? new MemoryDumpReaderFactory(new \DI\ContainerBuilder());
        $parsed = $parsed ?? self::reflectParse($factory, $output_path);
        if ($module_registry !== null) {
            $this->assertArrayHasKey(
                'module_registry',
                $parsed['module_globals'],
                'dump header must carry module_registry when resolved',
            );
            $this->assertSame($module_registry, $parsed['module_globals']['module_registry']);
        }
        if ($tsrm_ls_cache !== null) {
            $this->assertArrayHasKey(
                'tsrm_ls_cache',
                $parsed['module_globals'],
                'dump header must carry tsrm_ls_cache on ZTS targets',
            );
            $this->assertSame($tsrm_ls_cache, $parsed['module_globals']['tsrm_ls_cache']);
        }

        // Clean up
        unlink($output_path);
    }

    /**
     * MemoryDumpReaderFactory::parse() is private; reach it via
     * reflection so the regression assertion above can inspect the
     * dump's region index without re-implementing the parser.
     *
     * @return array{
     *     pid: int,
     *     php_version: string,
     *     eg_address: int,
     *     cg_address: int,
     *     rss_bytes: int|null,
     *     module_globals: array<string, int>,
     *     memory_areas: list<\Reli\Lib\Process\MemoryMap\ProcessMemoryArea>,
     *     region_index: list<array{address: int, size: int, file_offset: int}>,
     * }
     */
    private static function reflectParse(
        MemoryDumpReaderFactory $factory,
        string $path,
    ): array {
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("failed to open: {$path}");
        }
        try {
            $rc = new \ReflectionClass(MemoryDumpReaderFactory::class);
            $method = $rc->getMethod('parse');
            $method->setAccessible(true);
            /** @var array{
             *     pid: int,
             *     php_version: string,
             *     eg_address: int,
             *     cg_address: int,
             *     rss_bytes: int|null,
             *     module_globals: array<string, int>,
             *     memory_areas: list<\Reli\Lib\Process\MemoryMap\ProcessMemoryArea>,
             *     region_index: list<array{address: int, size: int, file_offset: int}>,
             * } $parsed
             */
            $parsed = $method->invoke($factory, $fp);
            return $parsed;
        } finally {
            fclose($fp);
        }
    }

    private function createGlobalsFinder(
        MemoryReader $memory_reader,
        ZendTypeReaderCreator $zend_type_reader_creator,
    ): PhpGlobalsFinder {
        $integer_reader = new LittleEndianReader();
        $binary_analysis_cache = new BinaryAnalysisCache(
            sys_get_temp_dir() . '/reli-test-' . uniqid(),
        );
        $process_memory_map_creator = ProcessMemoryMapCreator::create();
        $binary_fingerprint_creator = new BinaryFingerprintCreator(
            $memory_reader,
        );
        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser($integer_reader),
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                $integer_reader,
                new LinkMapLoader($memory_reader, $integer_reader),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache,
            ),
            $process_memory_map_creator,
            $binary_analysis_cache,
            new ProcExeReadlinkResolver(),
        );
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        return new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader,
            new PhpTsrmLsCacheFinder(
                $php_symbol_reader_creator,
                $tsrm_globals_resolver,
                $memory_reader,
                $integer_reader,
                new Elf64Parser($integer_reader),
                new CatFileReader(),
                ProcessMemoryMapCreator::create(),
                new ContainerAwarePathResolver(),
                $zend_type_reader_creator,
                $binary_analysis_cache,
                $binary_fingerprint_creator,
            ),
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
    }
}
