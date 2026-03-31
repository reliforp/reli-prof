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
use Reli\Lib\PhpProcessReader\PhpSymbolReaderCreator;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\TargetPhpVmProvider;

#[Group('target-version')]
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
        $this->markTestSkipped('Temporarily skipped: can cause SEGV that crashes subsequent tests');
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
        $this->markTestSkipped('Temporarily skipped: can cause SEGV that crashes subsequent tests');
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
        $this->markTestSkipped('Temporarily skipped: can cause SEGV that crashes subsequent tests');
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

    #[DataProvider('provideFromV80')]
    public function testGlobalCallbacksTracking(string $php_version, string $docker_image_name): void
    {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

        $target_script =
            <<<'CODE'
            <?php
            set_error_handler(function ($errno, $errstr) {
                return true;
            });
            set_exception_handler(function ($exception) {
            });
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

        $context_analyzer = new ContextAnalyzer();
        $sink = new ArrayContextTreeSink();
        $context_analyzer->analyze(
            $collected_memories->top_reference_context,
            $sink,
        );
        $contexts_analyzed = $sink->getResult();

        $this->assertArrayHasKey('global_callbacks', $contexts_analyzed);
        $this->assertArrayHasKey('error_handler', $contexts_analyzed['global_callbacks']);
        $this->assertArrayHasKey('exception_handler', $contexts_analyzed['global_callbacks']);
        $this->assertSame(
            'ObjectContext',
            $contexts_analyzed['global_callbacks']['error_handler']['#type'],
        );
        $this->assertArrayHasKey(
            'closure',
            $contexts_analyzed['global_callbacks']['error_handler'],
        );
        $this->assertSame(
            'ObjectContext',
            $contexts_analyzed['global_callbacks']['exception_handler']['#type'],
        );
        $this->assertArrayHasKey(
            'closure',
            $contexts_analyzed['global_callbacks']['exception_handler'],
        );
    }
}
