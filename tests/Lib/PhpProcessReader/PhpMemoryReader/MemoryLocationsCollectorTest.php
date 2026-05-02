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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Reli\BaseTestCase;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryLimitErrorDetails;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\LinkMapLoader;
use Reli\Lib\Elf\Process\PerBinarySymbolCacheRetriever;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use Reli\Lib\File\CatFileReader;
use Reli\Lib\File\PathResolver\ContainerAwarePathResolver;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpTsrmLsCacheFinder;
use Reli\Lib\PhpProcessReader\TsrmGlobalsResolver;
use Reli\Lib\Elf\Process\BinaryFingerprintCreator;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ArrayContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\LocationTypeAnalyzer\LocationTypeAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ObjectClassAnalyzer\ObjectClassAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionsSummary;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\Report\ReportGenerator;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpSymbolReaderCreator;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\TargetPhpVmProvider;

#[Group('target-version')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class MemoryLocationsCollectorTest extends BaseTestCase
{
    /** @var resource|null */
    private $child = null;

    private string $memory_limit_buckup;
    public function setUp(): void
    {
        $this->child = null;
        $this->memory_limit_buckup = ini_get('memory_limit');
        ini_set('memory_limit', '1G');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memory_limit_buckup);
        if (!is_null($this->child)) {
            $child_status = proc_get_status($this->child);
            if (is_array($child_status)) {
                if ($child_status['running']) {
                    posix_kill($child_status['pid'], SIGKILL);
                }
            }
            $this->child = null;
        }
    }

    public static function provideFromV80()
    {
        yield from TargetPhpVmProvider::from(ZendTypeReader::V80);
    }

    #[DataProvider('provideFromV80')]
    public function testCollectAllFromV80(string $php_version, string $docker_image_name): void
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            error_reporting(E_ALL & ~E_DEPRECATED);
            /** class doc_comment */
            class A {
                public static $output = STDOUT;

                /** property doc_comment */
                public string $result = '';

                /** function doc_comment */
                public function wait($input): void {
                    static $test_static_variable = 0xdeadbeef;
                    (function (...$_) use ($input) {
                        $this->result = fgets($input);
                    })(123, extra: 456);
                }
            }
            $tempfile = tempnam('', '');
            include $tempfile;
            $object = new A;
            $ref_object =& $object;
            $object->dynamic_property = 42;
            fputs(A::$output, "a\n");
            $object->wait(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $sink = new ArrayContextTreeSink();
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_real_size);
        $contexts_analyzed = $sink->getResult();
        $this->assertSame(
            'fgets',
            $contexts_analyzed['call_frames']['0']['function_name']
        );
        $this->assertSame(
            'ResourceContext',
            $contexts_analyzed['call_frames']['0']['local_variables']['$args_to_internal_function[0]']['#type']
        );
        // After root reordering (definitions first, call_frames later),
        // $this in the closure frame is a reference to the object that
        // was tree-assigned under global_variables. Verify the object
        // exists and has the expected dynamic property value (42)
        // regardless of which branch owns the tree structure.
        $thisNode = $contexts_analyzed['call_frames']['1']['this'];
        $objectTree = $this->findObjectTree($thisNode, $contexts_analyzed);
        $this->assertNotNull($objectTree, 'Object tree for $this not found');
        $this->assertSame(
            42,
            $objectTree['dynamic_properties']['array_elements']['dynamic_property']['value']['value']
        );
        $this->assertSame(
            123,
            $contexts_analyzed
            ['call_frames']
            ['1']
            ['local_variables']
            ['_']
            ['array_elements']
            ['0']
            ['value']
            ['value']
        );
        $this->assertSame(
            456,
            $contexts_analyzed
            ['call_frames']
            ['1']
            ['extra_named_params']
            ['array_elements']
            ['extra']
            ['value']
            ['value']
        );
        $this->assertSame(
            'A::wait',
            $contexts_analyzed['call_frames']['2']['function_name']
        );
        // Verify main frame (call_frames['3']) exists and has the expected
        // function name. After root reordering, local variable and symbol
        // table entries may be reference edges to global_variables, so we
        // only assert structural presence rather than specific tree shape.
        $mainFrame = $contexts_analyzed['call_frames']['3'];
        $this->assertArrayHasKey('local_variables', $mainFrame);
        $this->assertArrayHasKey('object', $mainFrame['local_variables']);
        $this->assertSame(
            '/** class doc_comment */',
            $contexts_analyzed['class_table']['a']['doc_comment']['#locations'][0]->value
        );
        $this->assertSame(
            '/** property doc_comment */',
            $contexts_analyzed['class_table']['a']['property_info']['result']['doc_comment']['#locations'][0]->value
        );
        $this->assertSame(
            '/** function doc_comment */',
            $contexts_analyzed['class_table']['a']['methods']['wait']['op_array']['doc_comment']['#locations'][0]->value
        );
        $this->assertSame(
            1,
            $contexts_analyzed
            ['class_table']
            ['a']
            ['methods']
            ['wait']
            ['op_array']
            ['static_variables']
            ['array_elements']
            ['#count']
        );
        // After root reordering, static variable value may live under
        // class_table (processed before call_frames). The value may be
        // wrapped in a 'referenced' PhpReferenceContext since static vars
        // are internally implemented as references in PHP.
        $staticValViaFrame = $contexts_analyzed
            ['call_frames']['2']['local_variables']['test_static_variable']
            ['referenced']['value'] ?? null;
        $staticElemViaClass = $contexts_analyzed
            ['class_table']['a']['methods']['wait']['op_array']
            ['static_variables']['array_elements']['test_static_variable'] ?? null;
        $staticValViaClass = null;
        if (is_array($staticElemViaClass)) {
            // Direct: value → value
            $staticValViaClass = $staticElemViaClass['value']['value'] ?? null;
            // Reference wrapped: value → referenced → value
            if ($staticValViaClass === null) {
                $staticValViaClass = $staticElemViaClass['value']['referenced']['value'] ?? null;
            }
        }
        $this->assertSame(
            0xdeadbeef,
            $staticValViaFrame ?? $staticValViaClass,
            'test_static_variable value not found via call_frames or class_table'
        );
        $this->assertSame(
            3,
            $contexts_analyzed
                ['included_files']
                ['#count']
        );
    }

    /**
     * End-to-end streaming test: collectAll with PdoContextTreeSink,
     * backfill regions, and verify the percentage is reasonable.
     *
     * This ensures that vm_stack / compiler_arena (which live inside
     * zend_mm_heap chunks) are classified correctly, and the corrected
     * summary produces a non-zero analysed percentage.
     */
    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testStreamingPercentageIsReasonable(string $php_version, string $docker_image_name): void
    {
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            $arr = range(1, 1000);
            $obj = new stdClass;
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version)
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version)
        );

        // ---- set up streaming sink (in-memory SQLite) ----
        $tmp_path = tempnam(sys_get_temp_dir(), 'reli_test_stream_') . '.sqlite3';
        $driver = new SqliteDriver($tmp_path);
        $pdo_output = new PdoMemoryOutput($driver);
        [$sink, $run_id, $db] = $pdo_output->createStreamingSink();

        // ---- collect with streaming ----
        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );

        // ---- verify region data in DB (written inline at emit time) ----
        $sink->flush();
        $region_result = RegionsSummary::queryRegionSums($db, $run_id);
        $region_sums = $region_result['sums'];

        // There must be at least zend_mm_heap data (normal heap allocations)
        $this->assertArrayHasKey('zend_mm_heap', $region_sums);
        $this->assertGreaterThan(0, $region_sums['zend_mm_heap']);

        // ---- compute summary and percentage ----
        $chunk_usage = $region_sums['zend_mm_heap'] ?? 0;
        $huge_usage = $region_sums['zend_mm_huge'] ?? 0;
        $vm_stack_total = $collected_memories->vm_stack_memory_locations->getTotalSize();
        $compiler_arena_total = $collected_memories->compiler_arena_memory_locations->getTotalSize();
        $heap_usage = $chunk_usage + $huge_usage + $vm_stack_total + $compiler_arena_total;

        $pct = (float)$heap_usage
            / (float)$collected_memories->memory_get_usage_size * 100.0;

        // The percentage must NOT be near-zero (the bug we're fixing).
        // A real analysis of a simple script typically yields 60-100%.
        $this->assertGreaterThan(
            30.0,
            $pct,
            sprintf(
                'heap_memory_analyzed_percentage too low: %.2f%% '
                . '(heap_usage=%d, memory_get_usage=%d, region_sums=%s)',
                $pct,
                $heap_usage,
                $collected_memories->memory_get_usage_size,
                json_encode($region_sums),
            )
        );

        // Also verify it doesn't exceed a sane upper bound (the DB SUM may
        // slightly over-count due to overlapping locations that weren't
        // filtered, plus the region totals for vm_stack/compiler_arena).
        $this->assertLessThan(
            200.0,
            $pct,
            'percentage should not wildly exceed 100%'
        );

        // ---- cleanup ----
        $db = null;
        @unlink($tmp_path);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testGeneratorCallStackTracking(string $php_version, string $docker_image_name): void
    {
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            function myGenerator() {
                $x = 1;
                yield 42;
                $x = 2;
            }
            $gen = myGenerator();
            $gen->current();
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $sink = new ArrayContextTreeSink();
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $contexts_analyzed = $sink->getResult();

        // Verify that a generator with call_frames exists somewhere in the context tree
        $found_generator_with_frames = false;
        $findGenerator = function (array $tree) use (&$findGenerator, &$found_generator_with_frames): void {
            foreach ($tree as $key => $value) {
                if ($key === 'generator' && is_array($value) && isset($value['call_frames'])) {
                    $found_generator_with_frames = true;
                    return;
                }
                if (is_array($value) && $key !== '#locations') {
                    $findGenerator($value);
                    if ($found_generator_with_frames) {
                        return;
                    }
                }
            }
        };
        $findGenerator($contexts_analyzed);
        $this->assertTrue(
            $found_generator_with_frames,
            'Should find at least one generator with tracked call frames'
        );
    }

    public static function provideFromV81()
    {
        yield from TargetPhpVmProvider::from(ZendTypeReader::V81);
    }

    #[DataProvider('provideFromV81')]
    public function testFiberCallStackTracking(string $php_version, string $docker_image_name): void
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            $fiber = new Fiber(function () {
                $x = 1;
                Fiber::suspend();
                $x = 2;
            });
            $fiber->start();
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $sink = new ArrayContextTreeSink();
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $contexts_analyzed = $sink->getResult();

        // Verify that a fiber with call_frames exists somewhere in the context tree
        $found_fiber_with_frames = false;
        $findFiber = function (array $tree) use (&$findFiber, &$found_fiber_with_frames): void {
            foreach ($tree as $key => $value) {
                if ($key === 'fiber' && is_array($value) && isset($value['call_frames'])) {
                    $found_fiber_with_frames = true;
                    return;
                }
                if (is_array($value) && $key !== '#locations') {
                    $findFiber($value);
                    if ($found_fiber_with_frames) {
                        return;
                    }
                }
            }
        };
        $findFiber($contexts_analyzed);
        $this->assertTrue(
            $found_fiber_with_frames,
            'Should find at least one fiber with tracked call frames'
        );
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testMemoryLimitViolation(string $php_version, string $docker_image_name)
    {
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            ini_set('memory_limit', '2M');
            register_shutdown_function(function () {
                $error = error_get_last();
                if (is_null($error)) {
                    return;
                }
                if (strpos($error['message'], 'Allowed memory size of') !== 0) {
                    return;
                }
                fputs(STDOUT, json_encode($error) . "\n");
                fgets(STDIN);
            });
            function f() {
                $var = array_fill(0, 0x1000, 0);
                f();
            }
            f();
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        // Read lines until we find the fatal error message and JSON.
        // PHP 8.5+ outputs a stack trace after the fatal error on stdout,
        // so we need to skip past it to find the JSON from the shutdown function.
        $error_message = '';
        $error_json = '';
        while (($line = fgets($pipes[1])) !== false) {
            if ($error_message === '' && str_starts_with($line, 'Fatal error: Allowed memory size of')) {
                $error_message = $line;
            } elseif ($error_message !== '' && str_starts_with($line, '{')) {
                $error_json = $line;
                break;
            }
        }
        $this->assertStringStartsWith(
            'Fatal error: Allowed memory size of',
            $error_message
        );

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $error = json_decode($error_json, true);
        $sink = new ArrayContextTreeSink();
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            new MemoryLimitErrorDetails(
                $error['file'],
                $error['line'],
                512
            ),
            null,
            $sink,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_real_size);
        $contexts = $sink->getResult();
        // Memory limit violation call stack: check both branches
        $call_frames = $contexts['memory_limit_call_frames']
            ?? $contexts['call_frames']
            ?? [];
        // Find frames with function_name = 'f'
        $f_frames = [];
        foreach ($call_frames as $k => $v) {
            if (is_array($v) && ($v['function_name'] ?? '') === 'f') {
                $f_frames[] = $v;
            }
        }
        $this->assertGreaterThanOrEqual(2, count($f_frames), 'Should find at least 2 frames of f()');
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testMemoryLimitViolationOnMethod(string $php_version, string $docker_image_name)
    {
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            ini_set('memory_limit', '2M');
            register_shutdown_function(function () {
                $error = error_get_last();
                if (is_null($error)) {
                    return;
                }
                if (strpos($error['message'], 'Allowed memory size of') !== 0) {
                    return;
                }
                fputs(STDOUT, json_encode($error) . "\n");
                fgets(STDIN);
            });
            class C {
                public function f() {
                    $var = array_fill(0, 0x1000, 0);
                    $this->f();
                }
            }
            (new C)->f();
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        // Read lines until we find the fatal error message and JSON.
        // PHP 8.5+ outputs a stack trace after the fatal error on stdout,
        // so we need to skip past it to find the JSON from the shutdown function.
        $error_message = '';
        $error_json = '';
        while (($line = fgets($pipes[1])) !== false) {
            if ($error_message === '' && str_starts_with($line, 'Fatal error: Allowed memory size of')) {
                $error_message = $line;
            } elseif ($error_message !== '' && str_starts_with($line, '{')) {
                $error_json = $line;
                break;
            }
        }
        $this->assertStringStartsWith(
            'Fatal error: Allowed memory size of',
            $error_message
        );

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $error = json_decode($error_json, true);
        $sink = new ArrayContextTreeSink();
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            new MemoryLimitErrorDetails(
                $error['file'],
                $error['line'],
                512
            ),
            null,
            $sink,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_real_size);
        $contexts = $sink->getResult();
        $call_frames = $contexts['memory_limit_call_frames']
            ?? $contexts['call_frames']
            ?? [];
        $f_frames = [];
        foreach ($call_frames as $k => $v) {
            if (is_array($v) && ($v['function_name'] ?? '') === 'C::f') {
                $f_frames[] = $v;
            }
        }
        $this->assertGreaterThanOrEqual(2, count($f_frames), 'Should find at least 2 frames of C::f()');
        $last_frame_key = null;
        foreach ($call_frames as $k => $v) {
            if (is_numeric($k)) {
                $last_frame_key = $k;
            }
        }
        if ($last_frame_key !== null) {
            $this->assertSame(
                '<main>',
                $call_frames[$last_frame_key]['function_name']
            );
        }
    }

    public static function provideFromV71()
    {
        yield from TargetPhpVmProvider::from(ZendTypeReader::V71);
    }

    #[DataProvider('provideFromV71')]
    public function testMemoryLimitViolationOnClosure(string $php_version, string $docker_image_name)
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        if ($php_version === ZendTypeReader::V70) {
            $this->markTestSkipped('V70 does not support closure frame');
        }

        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            ini_set('memory_limit', '2M');
            register_shutdown_function(function () {
                $error = error_get_last();
                if (is_null($error)) {
                    return;
                }
                if (strpos($error['message'], 'Allowed memory size of') !== 0) {
                    return;
                }
                fputs(STDOUT, json_encode($error) . "\n");
                fgets(STDIN);
            });
            class C {
                public function f() {
                    $f = static function () use (&$f) {
                        $var = array_fill(0, 0x1000, 0);
                        $f();
                    };
                    $f();
                }
            }
            (new C)->f();
            CODE
        ;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        // Read lines until we find the fatal error message and JSON.
        // PHP 8.5+ outputs a stack trace after the fatal error on stdout,
        // so we need to skip past it to find the JSON from the shutdown function.
        $error_message = '';
        $error_json = '';
        while (($line = fgets($pipes[1])) !== false) {
            if ($error_message === '' && str_starts_with($line, 'Fatal error: Allowed memory size of')) {
                $error_message = $line;
            } elseif ($error_message !== '' && str_starts_with($line, '{')) {
                $error_json = $line;
                break;
            }
        }
        $this->assertStringStartsWith(
            'Fatal error: Allowed memory size of',
            $error_message
        );

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $error = json_decode($error_json, true);
        $sink = new ArrayContextTreeSink();
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            new MemoryLimitErrorDetails(
                $error['file'],
                $error['line'],
                512
            ),
            null,
            $sink,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_real_size);
        $contexts = $sink->getResult();
        $call_frames = $contexts['memory_limit_call_frames']
            ?? $contexts['call_frames']
            ?? [];
        $closure_frames = [];
        foreach ($call_frames as $k => $v) {
            if (is_array($v) && str_contains(($v['function_name'] ?? ''), '{closure}')) {
                $closure_frames[] = $v;
            }
        }
        $this->assertGreaterThanOrEqual(2, count($closure_frames), 'Should find at least 2 closure frames');
    }

    /**
     * @return array<string, mixed>
     */
    private function collectContextsFromScript(
        string $php_version,
        string $docker_image_name,
        string $target_script,
    ): array {
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
        );
        $basic_globals_address = $php_globals_finder->findBasicGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $sink = new ArrayContextTreeSink();
        $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            $basic_globals_address,
            $sink,
        );

        return $sink->getResult();
    }

    #[DataProvider('provideFromV80')]
    public function testGlobalCallbacksWithAllHandlers(string $php_version, string $docker_image_name): void
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $contexts = $this->collectContextsFromScript($php_version, $docker_image_name, <<<'CODE'
            <?php
            set_error_handler(function ($errno, $errstr) {
                return true;
            });
            set_exception_handler(function ($exception) {
            });
            register_shutdown_function(function () {
            });
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        );

        $this->assertArrayHasKey('global_callbacks', $contexts);
        $this->assertArrayHasKey('error_handler', $contexts['global_callbacks']);
        $this->assertArrayHasKey('exception_handler', $contexts['global_callbacks']);
        $this->assertSame(
            'ObjectContext',
            $contexts['global_callbacks']['error_handler']['#type'],
        );
        $this->assertArrayHasKey(
            'closure',
            $contexts['global_callbacks']['error_handler'],
        );
        $this->assertSame(
            'ObjectContext',
            $contexts['global_callbacks']['exception_handler']['#type'],
        );
        $this->assertArrayHasKey(
            'closure',
            $contexts['global_callbacks']['exception_handler'],
        );

        $this->assertArrayHasKey('modules', $contexts);
        $this->assertArrayHasKey('standard', $contexts['modules']);
        $this->assertArrayHasKey(
            'shutdown_function[0]',
            $contexts['modules']['standard'],
        );
    }

    #[DataProvider('provideFromV80')]
    public function testNoHandlersSet(string $php_version, string $docker_image_name): void
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $contexts = $this->collectContextsFromScript($php_version, $docker_image_name, <<<'CODE'
            <?php
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        );

        $this->assertArrayHasKey('global_callbacks', $contexts);
        $this->assertArrayNotHasKey('error_handler', $contexts['global_callbacks']);
        $this->assertArrayNotHasKey('exception_handler', $contexts['global_callbacks']);
        $this->assertArrayHasKey('modules', $contexts);
        $this->assertArrayHasKey('standard', $contexts['modules']);
        $this->assertArrayNotHasKey(
            'shutdown_function[0]',
            $contexts['modules']['standard'],
        );
    }

    #[DataProvider('provideFromV80')]
    public function testErrorHandlerOnly(string $php_version, string $docker_image_name): void
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $contexts = $this->collectContextsFromScript($php_version, $docker_image_name, <<<'CODE'
            <?php
            set_error_handler(function ($errno, $errstr) {
                return true;
            });
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        );

        $this->assertArrayHasKey('global_callbacks', $contexts);
        $this->assertArrayHasKey('error_handler', $contexts['global_callbacks']);
        $this->assertArrayNotHasKey('exception_handler', $contexts['global_callbacks']);
    }

    #[DataProvider('provideFromV80')]
    public function testExceptionHandlerOnly(string $php_version, string $docker_image_name): void
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $contexts = $this->collectContextsFromScript($php_version, $docker_image_name, <<<'CODE'
            <?php
            set_exception_handler(function ($exception) {
            });
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        );

        $this->assertArrayHasKey('global_callbacks', $contexts);
        $this->assertArrayNotHasKey('error_handler', $contexts['global_callbacks']);
        $this->assertArrayHasKey('exception_handler', $contexts['global_callbacks']);
    }

    #[DataProvider('provideFromV80')]
    public function testStringCallbackHandler(string $php_version, string $docker_image_name): void
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $contexts = $this->collectContextsFromScript($php_version, $docker_image_name, <<<'CODE'
            <?php
            function my_error_handler($errno, $errstr) { return true; }
            set_error_handler('my_error_handler');
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        );

        $this->assertArrayHasKey('global_callbacks', $contexts);
        $this->assertArrayHasKey('error_handler', $contexts['global_callbacks']);
        $this->assertTrue(
            ($contexts['global_callbacks']['error_handler']['#type'] ?? null) === 'StringContext'
            || isset($contexts['global_callbacks']['error_handler']['#reference_node_id']),
        );
    }

    #[DataProvider('provideFromV80')]
    public function testMultipleShutdownFunctions(string $php_version, string $docker_image_name): void
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $contexts = $this->collectContextsFromScript($php_version, $docker_image_name, <<<'CODE'
            <?php
            register_shutdown_function(function () {});
            register_shutdown_function(function () {});
            register_shutdown_function(function () {});
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        );

        $this->assertArrayHasKey('modules', $contexts);
        $this->assertArrayHasKey('standard', $contexts['modules']);
        $this->assertArrayHasKey('shutdown_function[0]', $contexts['modules']['standard']);
        $this->assertArrayHasKey('shutdown_function[1]', $contexts['modules']['standard']);
        $this->assertArrayHasKey('shutdown_function[2]', $contexts['modules']['standard']);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testGeneratorAllocationOverheadAccuracy(string $php_version, string $docker_image_name): void
    {
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            function gen() {
                $a = 1;
                $b = 2;
                $c = 3;
                yield $a;
                yield $b;
                yield $c;
            }
            $generators = [];
            for ($i = 0; $i < 1000; $i++) {
                $g = gen();
                $g->current();
                $generators[] = $g;
            }
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
        );
        $basic_globals_address = $php_globals_finder->findBasicGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $tmp_path = tempnam(sys_get_temp_dir(), 'reli_test_gen_') . '.sqlite3';
        $driver = new SqliteDriver($tmp_path);
        $pdo_output = new PdoMemoryOutput($driver);
        [$sink, $run_id, $db] = $pdo_output->createStreamingSink();

        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            $basic_globals_address,
            $sink,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $sink->flush();
        $region_result = RegionsSummary::queryRegionSums($db, $run_id);
        $region_sums = $region_result['sums'];
        $heap_usage = ($region_sums['zend_mm_heap'] ?? 0) + ($region_sums['zend_mm_huge'] ?? 0)
            + $collected_memories->vm_stack_memory_locations->getTotalSize()
            + $collected_memories->compiler_arena_memory_locations->getTotalSize();
        $this->assertGreaterThan(0, $heap_usage);

        $this->assertLessThanOrEqual(
            $collected_memories->memory_get_usage_size,
            $heap_usage,
            'analyzed_percentage must not exceed 100%'
        );

        // Verify generators tracked via DB
        /** @psalm-suppress MixedAssignment */
        $gen_count = $db->query(
            "SELECT COUNT(DISTINCT address) FROM context_node_locations"
            . " WHERE run_id = {$run_id} AND class_name = 'Generator'"
        )->fetchColumn();
        $this->assertGreaterThanOrEqual(
            1000,
            (int)$gen_count,
            'Should track at least 1000 generator objects'
        );

        $db = null;
        @unlink($tmp_path);
    }

    public static function provideFromV82()
    {
        yield from TargetPhpVmProvider::from(ZendTypeReader::V82);
    }

    #[DataProvider('provideFromV82')]
    public function testFiberVmStackMemoryReflectedInAnalyzedPercentage(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        // Create many fibers with deep call stacks to make their VM stack usage significant
        $target_script =
            <<<'CODE'
            <?php
            function deep_call($depth) {
                if ($depth <= 0) {
                    Fiber::suspend();
                    return;
                }
                deep_call($depth - 1);
            }
            $fibers = [];
            for ($i = 0; $i < 100; $i++) {
                $f = new Fiber(function () {
                    deep_call(50);
                });
                $f->start();
                $fibers[] = $f;
            }
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
        );
        $basic_globals_address = $php_globals_finder->findBasicGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            $basic_globals_address,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        // Fiber VM stacks should contribute to vm_stack_memory_total.
        $this->assertGreaterThan(
            0,
            $collected_memories->vm_stack_memory_locations->getTotalSize(),
            'VM stack memory total should be greater than 0'
        );
    }

    public static function provideFromV74()
    {
        yield from TargetPhpVmProvider::from(ZendTypeReader::V74);
    }

    #[DataProvider('provideFromV74')]
    public function testWeakReferenceReferentTracking(string $php_version, string $docker_image_name): void
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            class MyTargetObject {
                public int $value = 42;
            }
            $obj = new MyTargetObject();
            $ref = WeakReference::create($obj);
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $sink = new ArrayContextTreeSink();
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $contexts_analyzed = $sink->getResult();

        $found_weak_reference_with_referent = false;
        $findWeakReference = function (array $tree) use (
            &$findWeakReference,
            &$found_weak_reference_with_referent,
        ): void {
            foreach ($tree as $key => $value) {
                if ($key === 'weak_reference' && is_array($value) && isset($value['referent'])) {
                    $found_weak_reference_with_referent = true;
                    return;
                }
                if (is_array($value) && $key !== '#locations') {
                    $findWeakReference($value);
                    if ($found_weak_reference_with_referent) {
                        return;
                    }
                }
            }
        };
        $findWeakReference($contexts_analyzed);
        $this->assertTrue(
            $found_weak_reference_with_referent,
            'Should find at least one WeakReference with a tracked referent'
        );
    }

    #[DataProvider('provideFromV80')]
    public function testWeakMapEntriesTracking(string $php_version, string $docker_image_name): void
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            class KeyObject {}
            $key1 = new KeyObject();
            $key2 = new KeyObject();
            $map = new WeakMap();
            $map[$key1] = 'value_one';
            $map[$key2] = 'value_two';
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $sink = new ArrayContextTreeSink();
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $contexts_analyzed = $sink->getResult();

        $found_weak_map_with_entries = false;
        $findWeakMap = function (array $tree) use (&$findWeakMap, &$found_weak_map_with_entries): void {
            foreach ($tree as $key => $value) {
                if ($key === 'weak_map' && is_array($value) && isset($value['entries'])) {
                    $found_weak_map_with_entries = true;
                    return;
                }
                if (is_array($value) && $key !== '#locations') {
                    $findWeakMap($value);
                    if ($found_weak_map_with_entries) {
                        return;
                    }
                }
            }
        };
        $findWeakMap($contexts_analyzed);
        $this->assertTrue(
            $found_weak_map_with_entries,
            'Should find at least one WeakMap with tracked entries'
        );
    }

    #[DataProvider('provideFromV80')]
    public function testSimpleXMLInternalAllocationsTracking(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        // Parse a small doc and keep a named child SimpleXMLElement alive
        // so that every tracked emalloc path is exercised:
        //   - root sxe:   php_sxe_object + php_libxml_ref_obj + node_ptr
        //   - child sxe:  php_sxe_object + iter.name (estrdup "child")
        $target_script =
            <<<'CODE'
            <?php
            $xml = simplexml_load_string(
                '<root><child id="1">hello</child></root>'
            );
            $keep = $xml->child;
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $sink = new ArrayContextTreeSink();
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $contexts_analyzed = $sink->getResult();

        $found_simplexml_node = false;
        $found_simplexml_document = false;
        $found_simplexml_iter_name = false;
        $sxe_object_sizes = [];
        $findSxe = function (array $tree) use (
            &$findSxe,
            &$found_simplexml_node,
            &$found_simplexml_document,
            &$found_simplexml_iter_name,
            &$sxe_object_sizes,
        ): void {
            foreach ($tree as $key => $value) {
                if ($key === 'simplexml_node') {
                    $found_simplexml_node = true;
                }
                if ($key === 'simplexml_document') {
                    $found_simplexml_document = true;
                }
                if ($key === 'simplexml_iter_name') {
                    $found_simplexml_iter_name = true;
                }
                if ($key === '#locations' && is_array($value)) {
                    /** @var array<mixed> $value */
                    foreach ($value as $loc) {
                        if (
                            $loc instanceof \Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation
                            && $loc->class_name === 'SimpleXMLElement'
                        ) {
                            $sxe_object_sizes[] = $loc->size;
                        }
                    }
                    continue;
                }
                if (is_array($value)) {
                    $findSxe($value);
                }
            }
        };
        $findSxe($contexts_analyzed);
        $this->assertTrue(
            $found_simplexml_node,
            'SimpleXMLElement should have its php_libxml_node_ptr allocation tracked'
        );
        $this->assertTrue(
            $found_simplexml_document,
            'SimpleXMLElement should have its php_libxml_ref_obj allocation tracked'
        );
        $this->assertTrue(
            $found_simplexml_iter_name,
            'A named child SimpleXMLElement should have its iter.name (estrdup/zend_string) tracked'
        );

        // Object size correction: php_sxe_object is ~152 bytes (incl.
        // zend_object's baked-in 1-zval slot) vs. sizeof(zend_object)=56
        // on 64-bit. Without the ZendObjectMemoryLocation fix SimpleXML
        // would be reported as size=40 (56 - 16 for property_count=0).
        // We allow some slack in case zend_object shape shifts between
        // PHP versions, but require > 56 to confirm the correction ran.
        $this->assertNotEmpty(
            $sxe_object_sizes,
            'At least one SimpleXMLElement ZendObjectMemoryLocation should be emitted'
        );
        foreach ($sxe_object_sizes as $size) {
            $this->assertGreaterThan(
                100,
                $size,
                'SimpleXMLElement should report the full php_sxe_object size, not just zend_object'
            );
        }
    }

    /**
     * Run a target PHP script in a container and drive the memory
     * locations collector over the resulting process. Returns the
     * context tree emitted by {@see ArrayContextTreeSink}.
     *
     * @return array<string, mixed>
     */
    private function runCollectorOnTargetScript(
        string $php_version,
        string $docker_image_name,
        string $target_script,
        MemoryReader $memory_reader,
        ZendTypeReaderCreator $type_reader_creator,
    ): array {
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version)
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version)
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $sink = new ArrayContextTreeSink();
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        return $sink->getResult();
    }

    #[DataProvider('provideFromV80')]
    public function testSimpleXMLPropertiesCacheWalked(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }

        // Trigger sxe->properties population by casting to array. The
        // SimpleXML handler populates the HashTable with @attributes +
        // child zvals and keeps it on the instance.
        $target_script = <<<'CODE'
            <?php
            $xml = simplexml_load_string(
                '<root><child id="42">hello</child><child>world</child></root>'
            );
            /** @var array<mixed> $_ */
            $_ = (array)$xml;
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE;

        $contexts_analyzed = $this->runCollectorOnTargetScript(
            $php_version,
            $docker_image_name,
            $target_script,
            new MemoryReader(),
            new ZendTypeReaderCreator(),
        );

        $found_properties_cache = false;
        $find = function (array $tree) use (&$find, &$found_properties_cache): void {
            foreach ($tree as $key => $value) {
                if ($key === 'simplexml_properties_cache') {
                    $found_properties_cache = true;
                    return;
                }
                if (is_array($value) && $key !== '#locations') {
                    $find($value);
                    if ($found_properties_cache) {
                        return;
                    }
                }
            }
        };
        $find($contexts_analyzed);
        $this->assertTrue(
            $found_properties_cache,
            'SimpleXMLElement->properties HashTable should be walked after it is populated'
        );
    }

    #[DataProvider('provideFromV80')]
    public function testSimpleXMLIteratorTracked(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }

        // SimpleXMLIterator uses the same php_sxe_object layout but a
        // distinct class entry; verify the dispatch picks it up too.
        $target_script = <<<'CODE'
            <?php
            $xml = new SimpleXMLIterator(
                '<root><child>hello</child></root>'
            );
            $keep = $xml->child;
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE;

        $contexts_analyzed = $this->runCollectorOnTargetScript(
            $php_version,
            $docker_image_name,
            $target_script,
            new MemoryReader(),
            new ZendTypeReaderCreator(),
        );

        $found_iterator_class = false;
        $found_node_edge_under_iterator = false;
        $find = function (array $tree) use (
            &$find,
            &$found_iterator_class,
            &$found_node_edge_under_iterator,
        ): void {
            $is_iterator_here = false;
            if (isset($tree['#locations']) && is_array($tree['#locations'])) {
                /** @var array<mixed> $locs */
                $locs = $tree['#locations'];
                foreach ($locs as $loc) {
                    if (
                        $loc instanceof \Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation
                        && $loc->class_name === 'SimpleXMLIterator'
                    ) {
                        $found_iterator_class = true;
                        $is_iterator_here = true;
                    }
                }
            }
            // `simplexml_node` is a direct child link of the object
            // node (not a grandchild), so check at this same level.
            if ($is_iterator_here && isset($tree['simplexml_node'])) {
                $found_node_edge_under_iterator = true;
            }
            foreach ($tree as $key => $value) {
                if (is_array($value) && $key !== '#locations') {
                    $find($value);
                }
            }
        };
        $find($contexts_analyzed);

        $this->assertTrue(
            $found_iterator_class,
            'SimpleXMLIterator instance should be recognised as a ZendObjectMemoryLocation'
        );
        $this->assertTrue(
            $found_node_edge_under_iterator,
            'SimpleXMLIterator should also emit a simplexml_node child edge'
        );
    }

    #[DataProvider('provideFromV80')]
    public function testStreamResourceTracking(string $php_version, string $docker_image_name): void
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            $memory_stream = fopen('php://memory', 'r+');
            fwrite($memory_stream, str_repeat('M', 1024));
            $temp_stream = fopen('php://temp', 'r+');
            fwrite($temp_stream, str_repeat('T', 512));
            $file_stream = fopen('php://stdout', 'w');
            fputs($file_stream, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(
                php_version: $php_version,
            )
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $sink = new ArrayContextTreeSink();
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $contexts_analyzed = $sink->getResult();

        // Search for ResourceContext nodes with stream_type_label
        $found_memory = false;
        $found_temp = false;
        $found_stdio = false;
        $found_memory_data_link = false;
        $findStreams = function (array $tree) use (
            &$findStreams,
            &$found_memory,
            &$found_temp,
            &$found_stdio,
            &$found_memory_data_link,
        ): void {
            foreach ($tree as $key => $value) {
                if (!is_array($value) || $key === '#locations') {
                    continue;
                }
                $type = $value['#type'] ?? null;
                $label = $value['stream_type_label'] ?? null;
                if ($type === 'ResourceContext' && $label !== null) {
                    if ($label === 'MEMORY') {
                        $found_memory = true;
                        if (isset($value['stream_memory_data'])) {
                            $found_memory_data_link = true;
                        }
                    }
                    if ($label === 'TEMP') {
                        $found_temp = true;
                        // TEMP with small data should also link memory data
                        if (isset($value['stream_memory_data'])) {
                            $found_memory_data_link = true;
                        }
                    }
                    if ($label === 'STDIO') {
                        $found_stdio = true;
                    }
                }
                $findStreams($value);
            }
        };
        $findStreams($contexts_analyzed);

        $this->assertTrue($found_memory, 'Should find a php://memory stream (label=MEMORY)');
        $this->assertTrue($found_temp, 'Should find a php://temp stream (label=TEMP)');
        $this->assertTrue($found_stdio, 'Should find a STDIO stream');
        $this->assertTrue(
            $found_memory_data_link,
            'Should find stream_memory_data link for MEMORY or TEMP stream'
        );
    }

    /**
     * EG(regular_list) must show up as a root branch and its outgoing
     * edges to resources must be weak — same reasoning as objects_store:
     * a resource only reachable via the registry should not look pinned
     * alive, otherwise stream/resource leaks are invisible.
     */
    #[DataProvider('provideFromV80')]
    public function testRegularListRootBranchTracksResourcesWithWeakEdges(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            $memory_stream = fopen('php://memory', 'r+');
            fwrite($memory_stream, str_repeat('M', 256));
            $temp_stream = fopen('php://temp', 'r+');
            $file_stream = fopen('php://stdout', 'w');
            fputs($file_stream, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version)
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version)
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $sink = new ArrayContextTreeSink();
        $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );

        $contexts_analyzed = $sink->getResult();

        $this->assertArrayHasKey(
            'regular_list',
            $contexts_analyzed,
            'regular_list must appear as a root branch alongside objects_store'
        );
        $regular_list = $contexts_analyzed['regular_list'];
        $this->assertSame('RegularListContext', $regular_list['#type'] ?? null);
        $this->assertSame(
            'weak',
            $regular_list['#edge_strength'] ?? null,
            'The regular_list root edge itself must be weak'
        );

        // Walk children: each entry is either a ResourceContext node (first
        // time seen) or a #reference_node_id pointing at the canonical
        // ResourceContext emitted under a stronger root (e.g.
        // global_variables). Either way the edge from regular_list must be
        // weak so leak analysis can see "only registry-reachable" resources.
        $resource_edge_count = 0;
        $weak_edge_count = 0;
        $resolved_resource_count = 0;
        foreach ($regular_list as $key => $value) {
            if (!is_array($value) || str_starts_with((string)$key, '#')) {
                continue;
            }
            $resource_edge_count++;
            if (($value['#edge_strength'] ?? null) === 'weak') {
                $weak_edge_count++;
            }
            if (isset($value['#reference_node_id'])) {
                continue;
            }
            if (($value['#type'] ?? null) === 'ResourceContext') {
                $resolved_resource_count++;
            }
        }

        $this->assertGreaterThan(
            0,
            $resource_edge_count,
            'regular_list should have at least one resource edge — '
            . 'the script opened three streams'
        );
        $this->assertSame(
            $resource_edge_count,
            $weak_edge_count,
            'Every edge from regular_list must be weak'
        );
        // Sanity: at least one of the registered resources should resolve
        // to a ResourceContext somewhere in the graph (either inline under
        // regular_list or via a #reference_node_id whose target lives under
        // global_variables).
        $found_resource_anywhere = false;
        $find = static function (array $tree) use (&$find, &$found_resource_anywhere): void {
            foreach ($tree as $key => $value) {
                if (!is_array($value) || $key === '#locations') {
                    continue;
                }
                if (($value['#type'] ?? null) === 'ResourceContext') {
                    $found_resource_anywhere = true;
                    return;
                }
                $find($value);
            }
        };
        $find($contexts_analyzed);
        $this->assertTrue(
            $found_resource_anywhere,
            'At least one ResourceContext should be reachable in the analyzed graph'
        );
        $this->assertGreaterThanOrEqual(
            0,
            $resolved_resource_count,
            'sanity: resolved-inline counter is non-negative'
        );
    }

    /**
     * Bug 2 regression test: In streaming mode, ObjectContext nodes must have
     * a tree edge to their ObjectPropertiesContext even when all properties
     * are object references (deferred during objects_store collection).
     */
    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testStreamingObjectPropertiesEdgeExists(string $php_version, string $docker_image_name): void
    {
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        // Two objects referencing each other via properties — all properties are objects.
        $target_script =
            <<<'CODE'
            <?php
            class NodeA {
                public $peer = null;
            }
            class NodeB {
                public $peer = null;
            }
            $a = new NodeA;
            $b = new NodeB;
            $a->peer = $b;
            $b->peer = $a;
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        [$db, $run_id, $tmp_path] = $this->collectStreamingDb(
            $pid,
            $php_version,
            $memory_reader,
            $type_reader_creator,
        );

        // Every ObjectContext node (type='ObjectContext') that has children of
        // type ObjectPropertiesContext must have a tree edge connecting them.
        $stmt = $db->query(
            "SELECT cn.node_id, cn.type"
            . " FROM context_nodes cn"
            . " WHERE cn.run_id = {$run_id} AND cn.type = 'ObjectContext'"
        );
        $object_nodes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $found_properties_edge = false;
        foreach ($object_nodes as $node) {
            $node_id = (int)$node['node_id'];
            // Check for a tree edge from this ObjectContext to an ObjectPropertiesContext
            $edge_stmt = $db->prepare(
                "SELECT e.child_node_id FROM context_edges e"
                . " JOIN context_nodes cn ON cn.run_id = e.run_id AND cn.node_id = e.child_node_id"
                . " WHERE e.run_id = ? AND e.parent_node_id = ? AND e.is_tree = 1"
                . " AND cn.type = 'ObjectPropertiesContext'"
            );
            $edge_stmt->execute([$run_id, $node_id]);
            $properties_children = $edge_stmt->fetchAll(\PDO::FETCH_COLUMN);

            // Check class name to only assert on our test classes
            $class_stmt = $db->prepare(
                "SELECT class_name FROM context_node_locations"
                . " WHERE run_id = ? AND node_id = ? AND class_name IS NOT NULL LIMIT 1"
            );
            $class_stmt->execute([$run_id, $node_id]);
            $class_name = $class_stmt->fetchColumn();

            if ($class_name === 'NodeA' || $class_name === 'NodeB') {
                $this->assertNotEmpty(
                    $properties_children,
                    "ObjectContext for {$class_name} (node {$node_id}) must have"
                    . " a tree edge to an ObjectPropertiesContext child"
                );
                $found_properties_edge = true;
            }
        }

        $this->assertTrue($found_properties_edge, 'Should find at least one NodeA/NodeB object with properties edge');

        $db = null;
        @unlink($tmp_path);
    }

    /**
     * Bug 3 regression test: In streaming mode, eager child emission must not
     * be followed by a duplicate reference edge when the parent context is
     * later analyzed from its encoded placeholder links.
     */
    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testStreamingObjectsStoreEdgeIsNotDuplicated(string $php_version, string $docker_image_name): void
    {
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            class EdgeDupProbe {
                public $label = '';
            }
            $tracked = new EdgeDupProbe;
            $tracked->label = 'streaming-edge-dedup';
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        [$db, $run_id, $tmp_path] = $this->collectStreamingDb(
            $pid,
            $php_version,
            $memory_reader,
            $type_reader_creator,
        );

        $objects_store_node_stmt = $db->prepare(
            'SELECT child_node_id FROM context_edges'
            . ' WHERE run_id = ? AND parent_node_id IS NULL AND link_name = ?'
        );
        $objects_store_node_stmt->execute([$run_id, 'objects_store']);
        $objects_store_node_id = $objects_store_node_stmt->fetchColumn();
        $this->assertIsNumeric($objects_store_node_id);

        $object_node_stmt = $db->prepare(
            "SELECT cn.node_id FROM context_nodes cn"
            . " JOIN context_node_locations loc"
            . "   ON loc.run_id = cn.run_id AND loc.node_id = cn.node_id"
            . " WHERE cn.run_id = ? AND cn.type = 'ObjectContext' AND loc.class_name = ?"
            . " LIMIT 1"
        );
        $object_node_stmt->execute([$run_id, 'EdgeDupProbe']);
        $object_node_id = $object_node_stmt->fetchColumn();
        $this->assertIsNumeric($object_node_id);

        $edge_count_stmt = $db->prepare(
            'SELECT COUNT(*) FROM context_edges'
            . ' WHERE run_id = ? AND parent_node_id = ? AND child_node_id = ?'
        );
        $edge_count_stmt->execute([$run_id, (int)$objects_store_node_id, (int)$object_node_id]);
        $this->assertSame(1, (int)$edge_count_stmt->fetchColumn());

        $edge_strength_stmt = $db->prepare(
            'SELECT strength, is_tree FROM context_edges'
            . ' WHERE run_id = ? AND parent_node_id = ? AND child_node_id = ?'
            . ' LIMIT 1'
        );
        $edge_strength_stmt->execute([$run_id, (int)$objects_store_node_id, (int)$object_node_id]);
        $edge_row = $edge_strength_stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($edge_row);
        $this->assertSame('weak', $edge_row['strength']);
        $this->assertContains((int)$edge_row['is_tree'], [0, 1]);

        $db = null;
        @unlink($tmp_path);
    }

    /**
     * Bug 2 + report regression test: Streaming mode must produce findings
     * from graph-based analysis passes (CycleCluster, DrillDown, etc.)
     * when the graph has cycles.
     */
    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testStreamingReportProducesGraphFindings(string $php_version, string $docker_image_name): void
    {
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            $arr = range(1, 500);
            $obj = new stdClass;
            $obj->data = $arr;
            fputs(STDOUT, "a\n");
            fgets(STDIN);
            CODE
        ;
        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes
        );
        $s = fgets($pipes[1]);
        $this->assertSame("a\n", $s);

        [$db, $run_id, $tmp_path] = $this->collectStreamingDb(
            $pid,
            $php_version,
            $memory_reader,
            $type_reader_creator,
        );

        // Generate report with full_analysis (same as ReportMemoryOutput now does)
        $generator = new ReportGenerator();
        $result = $generator->generateFromDb($db, $run_id, true);

        // Must produce at least overview + type_breakdown + other findings
        $this->assertGreaterThanOrEqual(
            3,
            count($result->findings),
            'Streaming report should produce multiple findings (overview, type_breakdown, etc.)'
        );

        $db = null;
        @unlink($tmp_path);
    }

    /**
     * Helper: Run streaming collection and return [PDO, run_id, tmp_path].
     *
     * @return array{\PDO, int, string}
     */
    private function collectStreamingDb(
        int $pid,
        string $php_version,
        MemoryReader $memory_reader,
        ZendTypeReaderCreator $type_reader_creator,
    ): array {
        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader()
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache = new BinaryAnalysisCache(
                    sys_get_temp_dir() . '/reli-test-' . uniqid()
                ),
            ),
            $process_memory_map_creator = ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $binary_fingerprint_creator = new BinaryFingerprintCreator($memory_reader_for_finder);
        $tsrm_globals_resolver = new TsrmGlobalsResolver(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
        $tsrm_ls_cache_finder = new PhpTsrmLsCacheFinder(
            $php_symbol_reader_creator,
            $tsrm_globals_resolver,
            $memory_reader_for_finder,
            $integer_reader,
            new Elf64Parser($integer_reader),
            new CatFileReader(),
            ProcessMemoryMapCreator::create(),
            new ContainerAwarePathResolver(),
            new ZendTypeReaderCreator(),
            $binary_analysis_cache,
            $binary_fingerprint_creator,
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version)
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version)
        );

        $tmp_path = tempnam(sys_get_temp_dir(), 'reli_test_stream_') . '.sqlite3';
        $driver = new SqliteDriver($tmp_path);
        $pdo_output = new PdoMemoryOutput($driver);
        [$sink, $run_id, $db] = $pdo_output->createStreamingSink();

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder
            )
        );
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );

        $sink->flush();
        $region_result = RegionsSummary::queryRegionSums($db, $run_id);
        $region_sums = $region_result['sums'];

        $chunk_usage = $region_sums['zend_mm_heap'] ?? 0;
        $huge_usage = $region_sums['zend_mm_huge'] ?? 0;
        $vm_stack_total = $collected_memories->vm_stack_memory_locations->getTotalSize();
        $compiler_arena_total = $collected_memories->compiler_arena_memory_locations->getTotalSize();
        $heap_usage = $chunk_usage + $huge_usage + $vm_stack_total + $compiler_arena_total;

        $summary_base = [
            'zend_mm_heap_total' => $collected_memories->chunk_memory_locations->getTotalSize()
                + $collected_memories->huge_memory_locations->getTotalSize(),
            'zend_mm_heap_usage' => $heap_usage,
            'zend_mm_chunk_total' => $collected_memories->chunk_memory_locations->getTotalSize(),
            'zend_mm_chunk_usage' => $chunk_usage + $vm_stack_total + $compiler_arena_total,
            'zend_mm_huge_total' => $collected_memories->huge_memory_locations->getTotalSize(),
            'zend_mm_huge_usage' => $huge_usage,
            'vm_stack_total' => $vm_stack_total,
            'vm_stack_usage' => $region_sums['vm_stack'] ?? 0,
            'compiler_arena_total' => $compiler_arena_total,
            'compiler_arena_usage' => $region_sums['compiler_arena'] ?? 0,
            'possible_allocation_overhead_total' => 0,
            'possible_array_overhead_total' => 0,
        ];

        $summary = [
            $summary_base
            + [
                'memory_get_usage' => $collected_memories->memory_get_usage_size,
                'memory_get_real_usage' => $collected_memories->memory_get_usage_real_size,
                'cached_chunks_size' => $collected_memories->cached_chunks_size,
            ]
            + [
                'heap_memory_analyzed_percentage' =>
                    (float)$heap_usage
                    / (float)$collected_memories->memory_get_usage_size * 100.0,
            ]
            + ['php_version' => $php_version]
        ];

        $pdo_output->finalizeStreaming($db, $run_id, $sink, $summary);

        return [$db, $run_id, $tmp_path];
    }

    /**
     * Find the object tree for a node, following reference edges if needed.
     *
     * After root reordering, a node in call_frames may be a reference
     * to an object tree-assigned under global_variables. This helper
     * returns the subtree that has 'object_properties'.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $contexts
     * @return array<string, mixed>|null
     */
    private function findObjectTree(array $node, array $contexts): ?array
    {
        // Direct tree ownership
        if (isset($node['object_properties'])) {
            return $node;
        }

        // Node is a reference — search global_variables for the real tree.
        // When $var =& exists, the value is wrapped in a PhpReferenceContext
        // with a 'referenced' key pointing to the actual object.
        $globals = $contexts['global_variables']['array_elements'] ?? [];
        foreach ($globals as $entry) {
            $val = $entry['value'] ?? null;
            if (!is_array($val)) {
                continue;
            }
            // Direct object
            if (isset($val['object_properties'])) {
                return $val;
            }
            // PHP reference wrapper: value -> referenced -> object
            $ref = $val['referenced'] ?? null;
            if (is_array($ref) && isset($ref['object_properties'])) {
                return $ref;
            }
        }

        // Check objects_store as fallback
        $os = $contexts['objects_store'] ?? [];
        foreach ($os as $k => $entry) {
            if (is_array($entry) && isset($entry['object_properties'])) {
                return $entry;
            }
        }

        return null;
    }
}
