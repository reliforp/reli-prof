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
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpTsrmLsCacheFinder;
use Reli\Lib\PhpProcessReader\TsrmGlobalsResolver;
use Reli\Lib\Elf\Process\BinaryFingerprintCreator;
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
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class PdoMemoryCollectionIntegrationTest extends BaseTestCase
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
    public function testCollectPdoSqliteMemory(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }

        $target_script = <<<'CODE'
            <?php
            $db = new PDO('sqlite::memory:');
            $db->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT, value TEXT)');
            $db->exec("INSERT INTO test (name, value) VALUES ('foo', 'bar')");
            $db->exec("INSERT INTO test (name, value) VALUES ('baz', 'qux')");
            $stmt = $db->prepare('SELECT * FROM test WHERE name = :name');
            $stmt->bindValue(':name', 'foo');
            $stmt->execute();
            $result = $stmt->fetchAll();
            fputs(STDOUT, "ready\n");
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
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
        );
        $tmp_path = tempnam(sys_get_temp_dir(), 'reli_test_pdo_') . '.sqlite3';
        $driver = new \Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver($tmp_path);
        $pdo_output = new \Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput($driver);
        [$sink, $run_id, $db] = $pdo_output->createStreamingSink();

        $collected_memories = $memory_locations_collector->collectAll(
            $process_specifier,
            $target_php_settings,
            $executor_globals_address,
            $compiler_globals_address,
            null,
            null,
            $sink,
        );

        $this->assertGreaterThan(0, $collected_memories->memory_get_usage_size);

        // Verify PDO and PDOStatement objects are detected
        $object_class_analyzer = new ObjectClassAnalyzer();
        $sink->flush();
        /** @psalm-suppress MixedAssignment */
        $pdo_count = $db->query(
            "SELECT COUNT(DISTINCT address) FROM context_node_locations"
            . " WHERE run_id = {$run_id} AND class_name = 'PDO'"
        )->fetchColumn();
        $this->assertSame(1, (int)$pdo_count);
        /** @psalm-suppress MixedAssignment */
        $stmt_count = $db->query(
            "SELECT COUNT(DISTINCT address) FROM context_node_locations"
            . " WHERE run_id = {$run_id} AND class_name = 'PDOStatement'"
        )->fetchColumn();
        $this->assertSame(1, (int)$stmt_count);

        /** @psalm-suppress MixedAssignment */
        $pdo_memory = $db->query(
            "SELECT MAX(size) FROM context_node_locations"
            . " WHERE run_id = {$run_id} AND class_name = 'PDO'"
        )->fetchColumn();
        $this->assertGreaterThan(0, (int)$pdo_memory);

        /** @psalm-suppress MixedAssignment */
        $stmt_memory = $db->query(
            "SELECT MAX(size) FROM context_node_locations"
            . " WHERE run_id = {$run_id} AND class_name = 'PDOStatement'"
        )->fetchColumn();
        $this->assertGreaterThan(0, $stmt_memory);

        // Verify pdo_dbh_t internal handle is tracked as PdoDbhMemoryLocation
        /** @psalm-suppress MixedAssignment */
        $pdo_dbh_count = $db->query(
            "SELECT COUNT(*) FROM context_node_locations"
            . " WHERE run_id = {$run_id} AND location_type = 'PdoDbhMemoryLocation'"
        )->fetchColumn();
        $this->assertGreaterThan(0, (int)$pdo_dbh_count, 'pdo_dbh_t should be tracked');

        $db = null;
        @unlink($tmp_path);
    }
}
