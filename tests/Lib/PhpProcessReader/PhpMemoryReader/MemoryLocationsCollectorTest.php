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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\LocationTypeAnalyzer\LocationTypeAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ObjectClassAnalyzer\ObjectClassAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\Result\RegionsSummary;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
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
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_real_size);

        $region_analyzer = new RegionAnalyzer(
            $collected_memories->chunk_memory_locations,
            $collected_memories->huge_memory_locations,
            $collected_memories->vm_stack_memory_locations,
            $collected_memories->compiler_arena_memory_locations
        );
        $region_analized = $region_analyzer->analyze($collected_memories->memory_locations);
        $this->assertGreaterThan(0, $region_analized->summary->zend_mm_heap_usage);
        $this->assertLessThanOrEqual(
            $collected_memories->memory_get_usage_size,
            $region_analized->summary->zend_mm_heap_usage
        );
        $this->assertSame(
            $collected_memories->memory_get_usage_real_size,
            $region_analized->summary->zend_mm_heap_total
        );
        $location_type_analyzer = new LocationTypeAnalyzer();
        $location_type_analized_result = $location_type_analyzer->analyze(
            $region_analized->regional_memory_locations->locations_in_zend_mm_heap,
        );
        $this->assertSame(
            2,
            $location_type_analized_result->per_type_usage['ZendObjectMemoryLocation']['count']
        );
        $object_class_analyzer = new ObjectClassAnalyzer();
        $object_class_analyzer_result = $object_class_analyzer->analyze(
            $region_analized->regional_memory_locations->locations_in_zend_mm_heap,
        );
        $this->assertSame(1, $object_class_analyzer_result->per_class_usage['A']['count']);
        $context_analyzer = new ContextAnalyzer();
        $sink = new ArrayContextTreeSink();
        $context_analyzer->analyze(
            $collected_memories->top_reference_context,
            $sink,
        );
        $contexts_analyzed = $sink->getResult();
        $this->assertSame(
            'fgets',
            $contexts_analyzed['call_frames']['0']['function_name']
        );
        $this->assertSame(
            'ResourceContext',
            $contexts_analyzed['call_frames']['0']['local_variables']['$args_to_internal_function[0]']['#type']
        );
        $this->assertSame(
            1,
            $contexts_analyzed
            ['call_frames']
            ['1']
            ['this']
            ['object_properties']
            ['#count']
        );
        $this->assertSame(
            42,
            $contexts_analyzed
            ['call_frames']
            ['1']
            ['this']
            ['dynamic_properties']
            ['array_elements']
            ['dynamic_property']
            ['value']
            ['value']
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
        $this->assertSame(
            $contexts_analyzed
                ['call_frames']
                ['3']
                ['local_variables']
                ['object']
                ['#node_id'],
            $contexts_analyzed
                ['call_frames']
                ['3']
                ['symbol_table']
                ['array_elements']
                ['ref_object']
                ['value']
                ['#reference_node_id']
        );
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
        $this->assertSame(
            0xdeadbeef,
            $contexts_analyzed
            ['call_frames']
            ['2']
            ['local_variables']
            ['test_static_variable']
            ['referenced']
            ['value']
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

        // ---- build RegionBoundaries and backfill ----
        $region_boundaries = new RegionBoundaries(
            $collected_memories->chunk_memory_locations,
            $collected_memories->huge_memory_locations,
            $collected_memories->vm_stack_memory_locations,
            $collected_memories->compiler_arena_memory_locations,
        );

        $region_analyzer = new RegionAnalyzer(
            $collected_memories->chunk_memory_locations,
            $collected_memories->huge_memory_locations,
            $collected_memories->vm_stack_memory_locations,
            $collected_memories->compiler_arena_memory_locations,
        );
        $analyzed_regions = $region_analyzer->analyze(
            $collected_memories->memory_locations,
        );

        $sink->flush();
        $region_boundaries->backfillRegions($db, $run_id);

        // ---- verify region data in DB ----
        $region_sums = RegionsSummary::queryRegionSums($db, $run_id);

        // There must be at least zend_mm_heap data (normal heap allocations)
        $this->assertArrayHasKey('zend_mm_heap', $region_sums);
        $this->assertGreaterThan(0, $region_sums['zend_mm_heap']);

        // vm_stack should exist inside a chunk
        $this->assertGreaterThan(
            0,
            $collected_memories->vm_stack_memory_locations->memory_locations !== []
                ? count($collected_memories->vm_stack_memory_locations->memory_locations)
                : 0,
            'vm_stack regions should be collected'
        );

        // If there are vm_stack locations in the DB they must be classified
        if (isset($region_sums['vm_stack'])) {
            $this->assertGreaterThan(0, $region_sums['vm_stack']);
        }

        // ---- compute corrected summary and percentage ----
        $summary_base = $analyzed_regions->summary->correctedToArray($region_sums);

        $pct = (float)$summary_base['zend_mm_heap_usage']
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
                $summary_base['zend_mm_heap_usage'],
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
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $context_analyzer = new ContextAnalyzer();
        $sink = new ArrayContextTreeSink();
        $context_analyzer->analyze(
            $collected_memories->top_reference_context,
            $sink,
        );
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
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $context_analyzer = new ContextAnalyzer();
        $sink = new ArrayContextTreeSink();
        $context_analyzer->analyze(
            $collected_memories->top_reference_context,
            $sink,
        );
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
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            new MemoryLimitErrorDetails(
                $error['file'],
                $error['line'],
                512
            )
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_real_size);
        $this->assertGreaterThan(
            3,
            $collected_memories->top_reference_context->call_frames->getFrameCount()
        );
        $this->assertSame(
            'f',
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt(3)
                ->function_name
        );
        $this->assertSame(
            $php_version >= ZendTypeReader::V81 ? 16 : 15,
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt(3)
                ->lineno
        );
        $this->assertSame(
            0x1000,
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt(4)
                ->getLocalVariable('var')
                ->getElements()
                ->getCount()
        );
        $this->assertSame(
            0x1000,
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt(5)
                ->getLocalVariable('var')
                ->getElements()
                ->getCount()
        );
        $last_frame = $collected_memories->top_reference_context->call_frames->getFrameCount() - 1;
        $this->assertSame(
            '<main>',
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt($last_frame)
                ->function_name
        );
        $this->assertSame(
            18,
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt($last_frame)
                ->lineno
        );
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
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            new MemoryLimitErrorDetails(
                $error['file'],
                $error['line'],
                512
            )
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_real_size);
        $this->assertGreaterThan(
            3,
            $collected_memories->top_reference_context->call_frames->getFrameCount()
        );
        $this->assertSame(
            'C::f',
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt(3)
                ->function_name
        );
        $this->assertSame(
            $php_version >= ZendTypeReader::V81 ? 17 : 16,
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt(3)
                ->lineno
        );
        $this->assertSame(
            0x1000,
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt(4)
                ->getLocalVariable('var')
                ->getElements()
                ->getCount()
        );
        $this->assertSame(
            0x1000,
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt(5)
                ->getLocalVariable('var')
                ->getElements()
                ->getCount()
        );
        $last_frame = $collected_memories->top_reference_context->call_frames->getFrameCount() - 1;
        $this->assertSame(
            '<main>',
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt($last_frame)
                ->function_name
        );
        $this->assertSame(
            20,
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt($last_frame)
                ->lineno
        );
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
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            new MemoryLimitErrorDetails(
                $error['file'],
                $error['line'],
                512
            )
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_real_size);
        $this->assertGreaterThan(
            2,
            $collected_memories->top_reference_context->call_frames->getFrameCount()
        );
        $this->assertSame(
            'C::{closure}(/source:16-19)',
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt(3)
                ->function_name
        );
        $this->assertSame(
            $php_version >= ZendTypeReader::V81 ? 18 : 17,
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt(3)
                ->lineno
        );
        $this->assertSame(
            0x1000,
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt(4)
                ->getLocalVariable('var')
                ->getElements()
                ->getCount()
        );
        $this->assertSame(
            0x1000,
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt(5)
                ->getLocalVariable('var')
                ->getElements()
                ->getCount()
        );
        $last_frame = $collected_memories->top_reference_context->call_frames->getFrameCount() - 1;
        $this->assertSame(
            '<main>',
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt($last_frame)
                ->function_name
        );
        $this->assertSame(
            23,
            $collected_memories->top_reference_context->call_frames
                ->getFrameAt($last_frame)
                ->lineno
        );
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
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            $basic_globals_address,
        );

        $context_analyzer = new ContextAnalyzer();
        $sink = new ArrayContextTreeSink();
        $context_analyzer->analyze(
            $collected_memories->top_reference_context,
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
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address,
            null,
            $basic_globals_address,
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $region_analyzer = new RegionAnalyzer(
            $collected_memories->chunk_memory_locations,
            $collected_memories->huge_memory_locations,
            $collected_memories->vm_stack_memory_locations,
            $collected_memories->compiler_arena_memory_locations
        );
        $region_analyzed = $region_analyzer->analyze($collected_memories->memory_locations);
        $this->assertGreaterThan(0, $region_analyzed->summary->zend_mm_heap_usage);

        // The key assertion: with many generators, analyzed heap usage must not
        // exceed memory_get_usage. Before the call frame overhead fix, this
        // ratio would exceed 120% due to double-counted bin overhead.
        $this->assertLessThanOrEqual(
            $collected_memories->memory_get_usage_size,
            $region_analyzed->summary->zend_mm_heap_usage,
            'analyzed_percentage must not exceed 100%'
        );

        $location_type_analyzer = new LocationTypeAnalyzer();
        $location_type_analyzed_result = $location_type_analyzer->analyze(
            $region_analyzed->regional_memory_locations->locations_in_zend_mm_heap,
        );
        // Verify that generators are tracked as ZendObjectMemoryLocation
        $this->assertGreaterThanOrEqual(
            1000,
            $location_type_analyzed_result->per_type_usage['ZendObjectMemoryLocation']['count']
                ?? 0,
            'Should track at least 1000 generator objects'
        );
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

        $region_analyzer = new RegionAnalyzer(
            $collected_memories->chunk_memory_locations,
            $collected_memories->huge_memory_locations,
            $collected_memories->vm_stack_memory_locations,
            $collected_memories->compiler_arena_memory_locations
        );
        $region_analyzed = $region_analyzer->analyze($collected_memories->memory_locations);
        $this->assertGreaterThan(0, $region_analyzed->summary->zend_mm_heap_usage);

        // Fiber VM stacks should contribute to vm_stack_memory_total.
        // With 100 fibers each having deep call stacks, the total VM stack memory
        // should be significantly larger than just the main thread's VM stack.
        $this->assertGreaterThan(
            0,
            $region_analyzed->summary->vm_stack_total,
            'VM stack memory total should be greater than 0'
        );

        // analyzed_percentage must not exceed 100%
        $this->assertLessThanOrEqual(
            $collected_memories->memory_get_usage_size,
            $region_analyzed->summary->zend_mm_heap_usage,
            'analyzed_percentage must not exceed 100%'
        );

        // analyzed_percentage should not be extremely low — with 100 fibers
        // having deep call stacks, the fiber VM stacks represent a significant
        // portion of heap usage. If the C stack scanning fails to find them,
        // analyzed_percentage drops well below 50%.
        $analyzed_percentage = (float)$region_analyzed->summary->zend_mm_heap_usage
            / (float)$collected_memories->memory_get_usage_size * 100.0;
        $this->assertGreaterThan(
            50.0,
            $analyzed_percentage,
            'analyzed_percentage should not be extremely low (fiber VM stacks should be found)'
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
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $context_analyzer = new ContextAnalyzer();
        $sink = new ArrayContextTreeSink();
        $context_analyzer->analyze(
            $collected_memories->top_reference_context,
            $sink,
        );
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
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $context_analyzer = new ContextAnalyzer();
        $sink = new ArrayContextTreeSink();
        $context_analyzer->analyze(
            $collected_memories->top_reference_context,
            $sink,
        );
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
        $collected_memories = $memory_locations_collector->collectAll(
            new ProcessSpecifier($pid),
            new TargetPhpSettings(php_version: $php_version),
            $executor_globals_address,
            $compiler_globals_address
        );
        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        $context_analyzer = new ContextAnalyzer();
        $sink = new ArrayContextTreeSink();
        $context_analyzer->analyze(
            $collected_memories->top_reference_context,
            $sink,
        );
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
}
