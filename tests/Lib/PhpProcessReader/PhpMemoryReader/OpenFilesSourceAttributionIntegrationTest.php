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
 * End-to-end coverage for the CG(open_files) source-buffer attribution: a real
 * PHP process whose primary script is a large source file must have that
 * script's in-memory source buffer (zend_file_handle.buf/len) attributed as a
 * MallocBuffer location under an `open_files` context — the multi-MB huge
 * allocation that a phar-shipped tool would otherwise leak as unattributed
 * heap.
 */
#[Group('target-version')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class OpenFilesSourceAttributionIntegrationTest extends BaseTestCase
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
    public function testAttributesPrimaryScriptSourceBuffer(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }

        // PHP 8.1 reworked zend_file_handle so the primary script is buffered
        // into `buf`/`len` and that allocation is held for the process
        // lifetime. Before 8.1 the primary script of a *plain* file is
        // streamed/mmap'd without a persistent zend_file_handle.buf, so there
        // is nothing for CG(open_files) attribution to find (the phar case,
        // which feeds compilation from a memory stream, is covered separately
        // by the ext/phar walker). Version strings are 'vNN', so the lexical
        // compare is safe across v70..v85.
        if ($php_version < 'v81') {
            $this->markTestSkipped(
                'CG(open_files) source buffer is retained for plain scripts'
                . ' only on PHP 8.1+ (pre-8.1 streams/mmaps the primary script)',
            );
        }

        // Pad the script with a large comment so the primary-script source
        // buffer is unmistakably sized: zend_stream_fixup() keeps a verbatim
        // copy of the whole file, so buf/len >= the padding length.
        $padding = str_repeat("/* reli open_files source-buffer marker */\n", 2000);
        $padding_len = strlen($padding);
        $target_script = "<?php\n" . $padding
            . "fputs(STDOUT, \"ready\\n\");\n"
            . "fgets(STDIN);\n";

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
        $tmp_path = tempnam(sys_get_temp_dir(), 'reli_test_openfiles_') . '.sqlite3';
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

        // EmitOpenFilesJob is the only producer of MallocBuffer locations on
        // this branch, so their presence proves the CG(open_files) walk fired
        // and attributed at least one compiled-file source buffer.
        $buffer_count = (int)$db->query(
            "SELECT COUNT(*) FROM context_node_locations"
            . " WHERE run_id = {$run_id}"
            . " AND location_type = 'MallocBufferMemoryLocation'"
        )->fetchColumn();
        $this->assertGreaterThan(
            0,
            $buffer_count,
            'the primary script source buffer must be attributed via CG(open_files)',
        );

        // Its size must be at least the padding we injected, proving the
        // attributed allocation really is this script's verbatim source
        // (zend_stream_fixup keeps a byte-for-byte copy of the whole file).
        $max_buffer_size = (int)$db->query(
            "SELECT MAX(size) FROM context_node_locations"
            . " WHERE run_id = {$run_id}"
            . " AND location_type = 'MallocBufferMemoryLocation'"
        )->fetchColumn();
        $this->assertGreaterThanOrEqual(
            $padding_len,
            $max_buffer_size,
            'the attributed source buffer must span the whole padded script',
        );

        $db = null;
        @unlink($tmp_path);
    }
}
