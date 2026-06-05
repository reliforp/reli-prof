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
 * End-to-end coverage for the ext/phar module-globals walker: a real PHP
 * process that has built and loaded a phar must have the phar's C-level
 * structures (the archive's char* buffers, manifest, per-entry structs)
 * attributed — reached through PHAR_G() / module_registry, not through the
 * PHP object graph.
 */
#[Group('target-version')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class PharWalkerIntegrationTest extends BaseTestCase
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
    public function testAttributesLoadedPharStructures(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }

        // Build a small phar at runtime and keep it loaded, so PHAR_G()'s
        // fname map holds a phar_archive_data with a populated manifest of
        // phar_entry_info structs.
        $target_script = <<<'CODE'
            <?php
            $path = '/tmp/reli-test-phar-' . getmypid() . '.phar';
            @unlink($path);
            $p = new Phar($path);
            $p->startBuffering();
            for ($i = 0; $i < 25; $i++) {
                $p->addFromString("dir/file{$i}.php", "<?php // " . str_repeat('x', 400) . "\n");
            }
            $p->setStub("<?php __HALT_COMPILER();");
            $p->stopBuffering();
            // Re-open so the archive stays mapped in PHAR_G(phar_fname_map).
            $loaded = new Phar($path);
            $count = count($loaded);
            fputs(STDOUT, "ready\n");
            fgets(STDIN);
            echo $count;
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
            '-dphar.readonly=0',
        );
        $s = fgets($pipes[1]);
        $this->assertSame("ready\n", $s);

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
        $module_registry_address = $php_globals_finder->findModuleRegistry(
            $process_specifier,
            $target_php_settings,
        );
        $this->assertNotNull(
            $module_registry_address,
            'module_registry is required to reach ext/phar globals',
        );
        // On ZTS the module's globals live in the per-thread TSRM block, so the
        // walker needs tsrm_ls_cache to resolve PHAR_G; null on NTS is fine.
        $tsrm_ls_cache_address = $php_globals_finder->findTsrmLsCache(
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
        $tmp_path = tempnam(sys_get_temp_dir(), 'reli_test_phar_') . '.sqlite3';
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
            module_registry_address: $module_registry_address,
            tsrm_ls_cache_address: $tsrm_ls_cache_address,
        );
        $sink->flush();

        // The phar walker emits its subtree under `phar.`-namespaced links
        // (phar.phar_fname_map / phar.buffers / ...). The dotted prefix avoids
        // false positives from the Phar/PharData/... class names that the
        // normal object walk also records as bare link names.
        $phar_edges = (int)$db->query(
            "SELECT COUNT(*) FROM context_edges"
            . " WHERE run_id = {$run_id} AND link_name LIKE 'phar.%'"
        )->fetchColumn();
        $this->assertGreaterThan(
            0,
            $phar_edges,
            'ext/phar module-globals subtree must be emitted',
        );

        // The archive's char* buffers are attributed as MallocBuffer
        // locations; on this branch ext/phar is their only producer.
        $malloc_buffers = (int)$db->query(
            "SELECT COUNT(*) FROM context_node_locations"
            . " WHERE run_id = {$run_id}"
            . " AND location_type = 'MallocBufferMemoryLocation'"
        )->fetchColumn();
        $this->assertGreaterThan(
            0,
            $malloc_buffers,
            'phar archive char* buffers must be attributed as MallocBuffer locations',
        );

        $db = null;
        @unlink($tmp_path);
    }
}
