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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\LocationTypeAnalyzer\LocationTypeAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionAnalyzer;
use Reli\Lib\PhpProcessReader\PhpSymbolReaderCreator;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\TargetPhpVmProvider;

#[Group('target-version')]
#[Group('pdo_mysql')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class PdoMysqlMemoryCollectionIntegrationTest extends BaseTestCase
{
    /** @var resource|null */
    private $child = null;
    private ?string $mysql_container_id = null;
    private ?string $docker_network = null;
    private string $memory_limit_backup;

    public function setUp(): void
    {
        $this->child = null;
        $this->memory_limit_backup = ini_get('memory_limit');
        ini_set('memory_limit', '1G');

        // Skip unless Docker is available
        exec('docker ps 2>/dev/null', $output, $exit_code);
        if ($exit_code !== 0) {
            $this->markTestSkipped('Docker is not available');
        }
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memory_limit_backup);
        if (!is_null($this->child)) {
            $child_status = proc_get_status($this->child);
            if (is_array($child_status) && $child_status['running']) {
                posix_kill($child_status['pid'], SIGKILL);
            }
            $this->child = null;
        }
        if ($this->mysql_container_id !== null) {
            exec("docker rm -f {$this->mysql_container_id} 2>/dev/null");
        }
        if ($this->docker_network !== null) {
            exec("docker network rm {$this->docker_network} 2>/dev/null");
        }
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testCollectPdoMysqlMysqlndBuffers(
        string $php_version,
        string $base_docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('No matching PHP versions for this target set');
        }

        // ZTS images don't have a pre-built pdo_mysql variant; skip for now
        if (str_contains($base_docker_image_name, '-zts')) {
            $this->markTestSkipped('pdo_mysql test not supported on ZTS images');
        }

        // Derive pdo_mysql image name: reli-test-php-mysql:7.4, reli-test-php-mysql:8.4, etc.
        // The version tag is extracted from the base image (e.g. "php:8.4-cli" -> "8.4")
        preg_match('/php:(\d+\.\d+)/', $base_docker_image_name, $m);
        $php_minor = $m[1] ?? '';
        if ($php_minor === '') {
            $this->markTestSkipped("Cannot determine PHP minor version from $base_docker_image_name");
        }
        $docker_image_name = "reli-test-php-mysql:$php_minor";

        // Check if the pre-built image exists; if not, build it on-the-fly
        exec("docker image inspect $docker_image_name 2>/dev/null", $inspect_output, $inspect_exit);
        if ($inspect_exit !== 0) {
            exec(
                "docker build -q -t $docker_image_name"
                . " --build-arg PHP_VERSION=$php_minor"
                . " -f Dockerfile-test-php-mysql . 2>/dev/null",
                $build_output,
                $build_exit,
            );
            if ($build_exit !== 0) {
                $this->markTestSkipped(
                    "Cannot build PHP $php_minor image with pdo_mysql"
                );
            }
        }

        // Create Docker network
        $this->docker_network = 'reli-test-mysql-' . uniqid();
        exec("docker network create {$this->docker_network} 2>&1", $output, $exit_code);
        if ($exit_code !== 0) {
            $this->markTestSkipped('Cannot create Docker network');
        }

        // Start MySQL container
        $mysql_password = 'reli_test_pass';
        $mysql_name = 'reli-mysql-' . uniqid();
        $cmd = sprintf(
            'docker run -d --network %s --name %s '
            . '-e MYSQL_ROOT_PASSWORD=%s '
            . '-e MYSQL_DATABASE=reli_test '
            . 'mysql:8.0 '
            . '--default-authentication-plugin=mysql_native_password 2>&1',
            escapeshellarg($this->docker_network),
            escapeshellarg($mysql_name),
            escapeshellarg($mysql_password),
        );
        $this->mysql_container_id = trim(shell_exec($cmd) ?? '');
        if (empty($this->mysql_container_id) || strlen($this->mysql_container_id) < 12) {
            $this->markTestSkipped(
                'Cannot start MySQL container (mysql:8.0 image may not be available)'
            );
        }

        // Wait for MySQL to be ready (first start can take ~30s)
        $mysql_ready = false;
        for ($i = 0; $i < 60; $i++) {
            $ping_output = [];
            exec(
                "docker exec {$this->mysql_container_id} mysqladmin ping -uroot -p{$mysql_password} 2>&1",
                $ping_output,
            );
            if (str_contains(implode('', $ping_output), 'alive')) {
                $mysql_ready = true;
                break;
            }
            sleep(2);
        }
        if (!$mysql_ready) {
            $this->markTestSkipped('MySQL did not become ready in time');
        }

        // Get MySQL container IP
        $mysql_ip = trim(shell_exec(
            "docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "
            . "{$this->mysql_container_id} 2>/dev/null"
        ) ?? '');
        if (empty($mysql_ip)) {
            $this->markTestSkipped('Cannot get MySQL container IP');
        }

        $target_script = <<<CODE
            <?php
            \$db = new PDO(
                'mysql:host={$mysql_ip};dbname=reli_test',
                'root',
                '{$mysql_password}',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            \$db->exec('CREATE TABLE test_data (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), value TEXT)');
            for (\$i = 0; \$i < 100; \$i++) {
                \$db->exec("INSERT INTO test_data (name, value) VALUES ('item_\$i', '" . str_repeat('x', 200) . "')");
            }
            \$stmt = \$db->prepare('SELECT * FROM test_data');
            \$stmt->execute();
            \$rows = \$stmt->fetchAll(PDO::FETCH_ASSOC);
            fputs(STDOUT, "ready\\n");
            fgets(STDIN);
            CODE;

        // Run PHP in the same Docker network
        $tmp_file = tempnam('/tmp/reli-test', 'reli-mysql-test');
        $pid_writer = tempnam('/tmp/reli-test', 'reli-mysql-test-pw');
        $pid_file = tempnam('/tmp/reli-test', 'reli-mysql-test-pid');
        chmod($tmp_file, 0777);
        chmod($pid_writer, 0777);
        chmod($pid_file, 0777);

        file_put_contents($pid_writer, <<<'CODE'
            <?php
            file_put_contents('/target-pid', getmypid());
            fputs(STDOUT, "pid written\n");
            CODE);
        file_put_contents($tmp_file, $target_script);

        $uid = posix_getuid();
        $gid = posix_getgid();

        $docker_command = [
            'docker', 'run', '--rm',
            '--pid', 'host',
            '--network', $this->docker_network,
            '-i',
            "-v$tmp_file:/source:rw",
            "-v$pid_writer:/pid-writer:rw",
            "-v$pid_file:/target-pid:rw",
            "-v/tmp/reli-test:/tmp/reli-test:rw",
            $docker_image_name,
            'php', '-dauto_prepend_file=/pid-writer', '/source',
        ];

        $pipes = [];
        $this->child = proc_open(
            $docker_command,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );

        [$pid_written, $pid_seen] = TargetPhpVmProvider::waitForMarkerLine($pipes[1], 'pid written');
        $this->assertTrue(
            $pid_written,
            "child did not print 'pid written'. Got: " . var_export($pid_seen, true),
        );
        $pid = (int)file_get_contents($pid_file);

        // Tolerate any leading noise (mysqlnd / MySQL-init chatter, startup
        // warnings) before the readiness marker instead of demanding it be
        // the very next line — that strict check was the flaky failure.
        [$ready, $seen] = TargetPhpVmProvider::waitForMarkerLine($pipes[1], 'ready');
        if (!$ready) {
            $stderr = stream_get_contents($pipes[2]);
            // Kill child and clean up before failing
            $child_status = proc_get_status($this->child);
            if (is_array($child_status) && $child_status['running']) {
                posix_kill($child_status['pid'], SIGKILL);
            }
            $this->child = null;
            $this->fail(
                "PHP script did not output 'ready'. Got: " . var_export($seen, true)
                . "\nStderr: " . $stderr
            );
        }

        // Set up memory analysis infrastructure
        $memory_reader = new MemoryReader();
        $type_reader_creator = new ZendTypeReaderCreator();
        $integer_reader = new LittleEndianReader();

        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(new CatFileReader(), new Elf64Parser($integer_reader)),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                $integer_reader,
                new LinkMapLoader($memory_reader, $integer_reader),
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
        $tmp_path = tempnam(sys_get_temp_dir(), 'reli_test_mysql_') . '.sqlite3';
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
        $sink->flush();

        // Verify PDO structures via DB
        /** @psalm-suppress MixedAssignment */
        $pdo_dbh_count = $db->query(
            "SELECT COUNT(*) FROM context_node_locations"
            . " WHERE run_id = {$run_id} AND location_type = 'PdoDbhMemoryLocation'"
        )->fetchColumn();
        $this->assertGreaterThan(0, (int)$pdo_dbh_count, 'pdo_dbh_t should be tracked for MySQL');

        /** @psalm-suppress MixedAssignment */
        $driver_data_count = $db->query(
            "SELECT COUNT(*) FROM context_node_locations"
            . " WHERE run_id = {$run_id} AND location_type = 'PdoDriverDataMemoryLocation'"
        )->fetchColumn();
        $this->assertGreaterThan(0, (int)$driver_data_count, 'pdo_mysql_db_handle should be tracked');

        /** @psalm-suppress MixedAssignment */
        $mysqlnd_count = $db->query(
            "SELECT COUNT(*) FROM context_node_locations"
            . " WHERE run_id = {$run_id} AND location_type = 'MysqlndMemoryLocation'"
        )->fetchColumn();
        $this->assertGreaterThan(0, (int)$mysqlnd_count, 'mysqlnd result buffers should be tracked');

        // With 100 rows fetched, we expect: MYSQLND_RES + MYSQLND_RES_BUFFERED
        // + row_buffers array + memory_pool(s) + arena chunk(s) = at least 3 locations
        $this->assertGreaterThanOrEqual(
            3,
            (int)$mysqlnd_count,
            "Expected at least 3 MysqlndMemoryLocation entries",
        );
        /** @psalm-suppress MixedAssignment */
        $mysqlnd_bytes = $db->query(
            "SELECT COALESCE(SUM(size), 0) FROM context_node_locations"
            . " WHERE run_id = {$run_id} AND location_type = 'MysqlndMemoryLocation'"
        )->fetchColumn();
        $this->assertGreaterThan(
            1000,
            (int)$mysqlnd_bytes,
            "mysqlnd buffer tracking should capture significant memory",
        );

        $db = null;
        @unlink($tmp_path);
    }
}
