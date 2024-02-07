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
use Reli\BaseTestCase;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryLimitErrorDetails;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\PerBinarySymbolCacheRetriever;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use Reli\Lib\File\CatFileReader;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
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
            $memory_reader,
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
            ),
            ProcessMemoryMapCreator::create(),
            new LittleEndianReader()
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            new LittleEndianReader(),
            new MemoryReader()
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
        $contexts_analyzed = $context_analyzer->analyze(
            $collected_memories->top_reference_context
        );
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
        fgets($pipes[1]);
        $error_message = fgets($pipes[1]);
        $this->assertStringStartsWith(
            'Fatal error: Allowed memory size of',
            $error_message
        );
        $error_json = fgets($pipes[1]);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            $memory_reader,
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
            ),
            ProcessMemoryMapCreator::create(),
            new LittleEndianReader()
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            new LittleEndianReader(),
            new MemoryReader()
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
        fgets($pipes[1]);
        $error_message = fgets($pipes[1]);
        $this->assertStringStartsWith(
            'Fatal error: Allowed memory size of',
            $error_message
        );
        $error_json = fgets($pipes[1]);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            $memory_reader,
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
            ),
            ProcessMemoryMapCreator::create(),
            new LittleEndianReader()
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            new LittleEndianReader(),
            new MemoryReader()
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
        fgets($pipes[1]);
        $error_message = fgets($pipes[1]);
        $this->assertStringStartsWith(
            'Fatal error: Allowed memory size of',
            $error_message
        );
        $error_json = fgets($pipes[1]);

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            $memory_reader,
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    )
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
            ),
            ProcessMemoryMapCreator::create(),
            new LittleEndianReader()
        );
        $php_globals_finder = new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            new LittleEndianReader(),
            new MemoryReader()
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
}
