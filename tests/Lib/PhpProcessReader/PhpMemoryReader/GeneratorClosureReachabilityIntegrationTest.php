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

use PHPUnit\Framework\Attributes\DataProviderExternal;
use Reli\Lib\PhpProcessReader\MainExecutable\ProcExeReadlinkResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Reli\BaseTestCase;
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
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpTsrmLsCacheFinder;
use Reli\Lib\PhpProcessReader\TsrmGlobalsResolver;
use Reli\Lib\Elf\Process\BinaryFingerprintCreator;
use Reli\Lib\PhpProcessReader\PhpSymbolReaderCreator;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\TargetPhpVmProvider;

/**
 * End-to-end coverage for the "walker reachability" fixes: against a real PHP
 * process that parks objects behind a suspended `yield from` chain and an
 * executing closure frame, the memory walker must actually reach those objects
 * and wire the structural edges (sub-generator `node[]` links, the closure
 * object edge) rather than leaving them to the objects_store fallback.
 *
 * The unit tests (ZendGeneratorTest / ZendExecuteDataClosureTest) pin the
 * byte-level extraction; this test proves the whole pipeline extracts the
 * information from a live target.
 */
#[Group('target-version')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class GeneratorClosureReachabilityIntegrationTest extends BaseTestCase
{
    /** @var resource|null */
    private $child = null;

    private string $memory_limit_backup;

    public function setUp(): void
    {
        $this->child = null;
        $this->memory_limit_backup = ini_get('memory_limit');
        ini_set('memory_limit', '1G');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memory_limit_backup);
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

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testReachesGeneratorAndClosureHeldObjects(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }

        // The marker objects below are reachable ONLY through the paths the
        // reachability fixes restore:
        //   - ReliGenLeafMarker:    a local of the child generator's suspended
        //     frame, itself reached only via the parent's `yield from` node[].
        //   - ReliClosureMarker:    captured by a closure that is parked mid
        //     execution, so its frame carries ZEND_CALL_CLOSURE.
        $target_script = <<<'CODE'
            <?php
            // Untyped properties on purpose: typed properties are 7.4+ and
            // this target also runs on 7.0-7.3.
            class ReliGenLeafMarker {
                public $tag = 'gen-leaf';
            }
            class ReliClosureMarker {
                public $tag = 'closure-captured';
            }

            function reli_child_gen(): \Generator {
                $leaf = new ReliGenLeafMarker();
                yield 1;
                // keep $leaf live across the suspension point
                echo $leaf->tag;
            }
            function reli_parent_gen(): \Generator {
                yield from reli_child_gen();
            }

            // Suspend the parent inside `yield from`: the child generator runs
            // to its first yield and is now referenced only via parent->node.
            $parent = reli_parent_gen();
            $parent->current();

            // Park a closure mid-execution so its frame is on the call stack
            // with ZEND_CALL_CLOSURE set when reli attaches.
            $closure_marker = new ReliClosureMarker();
            $cl = function () use ($closure_marker) {
                fputs(STDOUT, "ready\n");
                fgets(STDIN);
                return $closure_marker;
            };
            $cl();
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );
        [$ready, $seen] = TargetPhpVmProvider::waitForMarkerLine($pipes[1], 'ready');
        $this->assertTrue($ready, "child did not print 'ready'. Got: " . var_export($seen, true));

        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();

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
            new ProcExeReadlinkResolver(),
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

        $process_specifier = new ProcessSpecifier($pid);
        $target_php_settings = new TargetPhpSettings(php_version: $php_version);

        $executor_globals_address = $php_globals_finder->findExecutorGlobals(
            $process_specifier,
            $target_php_settings,
        );
        $compiler_globals_address = $php_globals_finder->findCompilerGlobals(
            $process_specifier,
            $target_php_settings,
        );

        $memory_locations_collector = new MemoryLocationsCollector(
            $memory_reader,
            $type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                ProcessMemoryMapCreator::create(),
                $type_reader_creator,
                $php_globals_finder,
            ),
            ProcessMemoryMapCreator::create(),
            new BinaryAnalysisCache(sys_get_temp_dir()),
            new ContainerAwarePathResolver(),
        );
        $tmp_path = tempnam(sys_get_temp_dir(), 'reli_test_genclosure_') . '.sqlite3';
        $driver = new \Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver($tmp_path);
        $pdo_output = new \Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput($driver);
        [$sink, $run_id, $db] = $pdo_output->createStreamingSink();

        $memory_locations_collector->collectAll(
            $process_specifier,
            $target_php_settings,
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );
        $sink->flush();

        // --- the marker objects must be reached at all ---
        $this->assertSame(
            1,
            $this->countClass($db, $run_id, 'ReliGenLeafMarker'),
            'object held only by a suspended `yield from` child frame must be reached',
        );
        $this->assertSame(
            1,
            $this->countClass($db, $run_id, 'ReliClosureMarker'),
            'object captured by a parked closure frame must be reached',
        );

        // --- the structural edges the fixes synthesize must exist ---
        // `node[N]` edges come only from EmitGeneratorJob walking the raw
        // zend_generator* pointers of a `yield from` chain.
        $this->assertGreaterThan(
            0,
            $this->countEdgeLinkLike($db, $run_id, 'node[%'),
            '`yield from` sub-generator node[] edge must be wired',
        );

        // The leaf marker must hang under a generator context (i.e. it was
        // reached through the generator walk, not merely swept up by the
        // objects_store fallback). `node_id IN (locations of class Generator)`
        // proves the leaf's owning frame is inside a generator subtree.
        $generator_node_count = (int)$db->query(
            "SELECT COUNT(*) FROM context_node_locations"
            . " WHERE run_id = {$run_id} AND class_name = 'Generator'"
        )->fetchColumn();
        $this->assertGreaterThanOrEqual(
            2,
            $generator_node_count,
            'both the parent and the yield-from child generator must be reached',
        );

        $db = null;
        @unlink($tmp_path);
    }

    private function countClass(\PDO $db, int $run_id, string $class_name): int
    {
        /** @psalm-suppress MixedAssignment */
        $count = $db->query(
            "SELECT COUNT(DISTINCT address) FROM context_node_locations"
            . " WHERE run_id = {$run_id}"
            . " AND class_name = " . $db->quote($class_name)
        )->fetchColumn();
        return (int)$count;
    }

    private function countEdgeLinkLike(\PDO $db, int $run_id, string $link_pattern): int
    {
        /** @psalm-suppress MixedAssignment */
        $count = $db->query(
            "SELECT COUNT(*) FROM context_edges"
            . " WHERE run_id = {$run_id}"
            . " AND link_name LIKE " . $db->quote($link_pattern)
        )->fetchColumn();
        return (int)$count;
    }
}
