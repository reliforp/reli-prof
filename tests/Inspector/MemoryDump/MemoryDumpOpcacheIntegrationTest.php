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

use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\DataProviderExternal;
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
class MemoryDumpOpcacheIntegrationTest extends BaseTestCase
{
    private const OPCACHE_INI = '-dopcache.enable_cli=1'
        . ' -dopcache.enable=1';

    private static function opcacheIniFor(string $php_version): string
    {
        // PHP 8.5+ ships opcache as a built-in Zend extension.
        // Older versions need explicit loading via zend_extension.
        $load = version_compare(
            ltrim($php_version, 'v'),
            '85',
            '<',
        ) ? '-dzend_extension=opcache ' : '';
        return $load . self::OPCACHE_INI;
    }

    /** @var resource|null */
    private $child = null;

    /** @var list<string> */
    private array $temp_files = [];

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
        foreach ($this->temp_files as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'opcacheSupported')]
    public function testDumpWithOpcacheEnabled(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no matching target');
        }
        $memory_reader = new MemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();
        $process_memory_map_creator = ProcessMemoryMapCreator::create();

        $target_script = <<<'CODE'
            <?php
            $tmpdir = '/tmp/opcache_test_' . getmypid();
            @mkdir($tmpdir, 0777, true);
            for ($i = 0; $i < 5; $i++) {
                $f = $tmpdir . '/class_' . $i . '.php';
                $code = '<?php class OpcacheClass' . $i
                    . ' { public static array $data = [];'
                    . ' public static function work(): int'
                    . ' { self::$data = array_fill(0, 50, "v' . $i . '");'
                    . ' return count(self::$data); } }';
                file_put_contents($f, $code);
                require $f;
            }
            OpcacheClass0::work();
            OpcacheClass1::work();
            OpcacheClass2::work();
            if (function_exists('opcache_get_status')) {
                $status = @opcache_get_status(false);
                if ($status !== false && ($status['opcache_enabled'] ?? false)) {
                    fputs(STDOUT, "ready\n");
                } else {
                    fputs(STDOUT, "opcache_disabled\n");
                }
            } else {
                fputs(STDOUT, "opcache_disabled\n");
            }
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
            self::opcacheIniFor($php_version),
        );

        $this->waitForOpcacheReady($pipes[1], $docker_image_name);

        $globals_finder = $this->createGlobalsFinder(
            $memory_reader,
            $zend_type_reader_creator,
        );
        $process = new ProcessSpecifier($pid);
        $settings = new TargetPhpSettings(php_version: $php_version);

        $eg = $globals_finder->findExecutorGlobals($process, $settings);
        $cg = $globals_finder->findCompilerGlobals($process, $settings);

        $dumper = new MemoryDumper(
            $memory_reader,
            $zend_type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                $process_memory_map_creator,
                $zend_type_reader_creator,
            ),
            $process_memory_map_creator,
        );

        $output_path = tempnam(sys_get_temp_dir(), 'reli-opcache-dump-');
        $this->assertNotFalse($output_path);
        $this->temp_files[] = $output_path;

        $result = $dumper->dump(
            $process,
            $settings,
            $eg,
            $cg,
            $output_path,
        );

        $this->assertFileExists($result->output_path);
        $this->assertGreaterThan(0, $result->region_count);
        $this->assertGreaterThan(
            64 * 1024,
            $result->total_bytes,
            'Dump with opcache should contain meaningful data',
        );

        // Verify the dump file is valid by parsing the header
        $fp = fopen($output_path, 'rb');
        $this->assertNotFalse($fp);
        $magic = fread($fp, 8);
        $this->assertSame("RDUMP\0\0\0", $magic);
        fclose($fp);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'opcacheSupported')]
    public function testDumpRoundtripWithOpcache(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no matching target');
        }
        $memory_reader = new MemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();
        $process_memory_map_creator = ProcessMemoryMapCreator::create();

        $target_script = <<<'CODE'
            <?php
            $tmpdir = '/tmp/opcache_rt_' . getmypid();
            @mkdir($tmpdir, 0777, true);
            $f = $tmpdir . '/Cached.php';
            $code = '<?php class CachedClass {'
                . ' public static function run(): string'
                . ' { return str_repeat("x", 1024); } }';
            file_put_contents($f, $code);
            require $f;
            $val = CachedClass::run();
            if (function_exists('opcache_get_status')) {
                $status = @opcache_get_status(false);
                if ($status !== false && ($status['opcache_enabled'] ?? false)) {
                    fputs(STDOUT, "ready\n");
                } else {
                    fputs(STDOUT, "opcache_disabled\n");
                }
            } else {
                fputs(STDOUT, "opcache_disabled\n");
            }
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
            self::opcacheIniFor($php_version),
        );

        $this->waitForOpcacheReady($pipes[1], $docker_image_name);

        $globals_finder = $this->createGlobalsFinder(
            $memory_reader,
            $zend_type_reader_creator,
        );
        $process = new ProcessSpecifier($pid);
        $settings = new TargetPhpSettings(php_version: $php_version);

        $eg = $globals_finder->findExecutorGlobals($process, $settings);
        $cg = $globals_finder->findCompilerGlobals($process, $settings);

        $dumper = new MemoryDumper(
            $memory_reader,
            $zend_type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                $process_memory_map_creator,
                $zend_type_reader_creator,
            ),
            $process_memory_map_creator,
        );

        $output_path = tempnam(sys_get_temp_dir(), 'reli-opcache-rt-');
        $this->assertNotFalse($output_path);
        $this->temp_files[] = $output_path;

        $result = $dumper->dump(
            $process,
            $settings,
            $eg,
            $cg,
            $output_path,
        );

        $this->assertFileExists($result->output_path);
        $this->assertGreaterThan(0, $result->region_count);

        // Read back the dump file via MemoryDumpReaderFactory
        $factory = new MemoryDumpReaderFactory(new ContainerBuilder());
        $reader = $factory->createFromPath($output_path, []);
        $this->assertInstanceOf(MemoryDumpReader::class, $reader);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'opcacheSupported')]
    public function testLightweightDumpSmallerThanFullShm(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no matching target');
        }
        $memory_reader = new MemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();
        $process_memory_map_creator = ProcessMemoryMapCreator::create();

        // opcache.memory_consumption defaults to 128MB; the actual
        // resident pages should be a small fraction of that.
        $target_script = <<<'CODE'
            <?php
            $tmpdir = '/tmp/opcache_lw_' . getmypid();
            @mkdir($tmpdir, 0777, true);
            $f = $tmpdir . '/Small.php';
            file_put_contents($f, '<?php class SmallClass { public static function id(): int { return 1; } }');
            require $f;
            SmallClass::id();
            if (function_exists('opcache_get_status')) {
                $status = @opcache_get_status(false);
                if ($status !== false && ($status['opcache_enabled'] ?? false)) {
                    fputs(STDOUT, (string)($status['memory_usage']['used_memory'] ?? 0) . "\n");
                    fputs(STDOUT, "ready\n");
                } else {
                    fputs(STDOUT, "0\n");
                    fputs(STDOUT, "opcache_disabled\n");
                }
            } else {
                fputs(STDOUT, "0\n");
                fputs(STDOUT, "opcache_disabled\n");
            }
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
            self::opcacheIniFor($php_version),
        );

        $opcache_used_str = $this->waitForOpcacheReadyWithUsage(
            $pipes[1],
            $docker_image_name,
        );
        $opcache_used = (int)trim($opcache_used_str);

        $globals_finder = $this->createGlobalsFinder(
            $memory_reader,
            $zend_type_reader_creator,
        );
        $process = new ProcessSpecifier($pid);
        $settings = new TargetPhpSettings(php_version: $php_version);

        $eg = $globals_finder->findExecutorGlobals($process, $settings);
        $cg = $globals_finder->findCompilerGlobals($process, $settings);

        $dumper = new MemoryDumper(
            $memory_reader,
            $zend_type_reader_creator,
            new PhpZendMemoryManagerChunkFinder(
                $process_memory_map_creator,
                $zend_type_reader_creator,
            ),
            $process_memory_map_creator,
        );

        $output_path = tempnam(sys_get_temp_dir(), 'reli-opcache-lw-');
        $this->assertNotFalse($output_path);
        $this->temp_files[] = $output_path;

        $result = $dumper->dump(
            $process,
            $settings,
            $eg,
            $cg,
            $output_path,
        );

        $this->assertGreaterThan(0, $result->region_count);

        // The default opcache.memory_consumption is 128MB.
        // With pagemap-based resident page filtering, the dump should
        // be much smaller than the full SHM region size.
        // A tiny script should use only a few MB of opcache at most.
        $shm_total = 128 * 1024 * 1024; // default opcache SHM size
        $this->assertLessThan(
            $shm_total,
            $result->total_bytes,
            'Lightweight dump should be smaller than full SHM region ('
            . $shm_total . ' bytes)',
        );

        // The dump size should be reasonably proportional to actual usage
        if ($opcache_used > 0) {
            // Allow generous headroom: dump includes ZendMM chunks,
            // heap, binary segments, etc. in addition to opcache SHM.
            // But the total should still be well under 128MB.
            $this->assertLessThan(
                64 * 1024 * 1024,
                $result->total_bytes,
                'Dump for a tiny script should be well under 64MB',
            );
        }

        unlink($output_path);
    }

    /**
     * Read lines from stdout until "ready" or "opcache_disabled".
     * Skips warning lines (e.g. failed extension loading).
     * @param resource $stdout
     */
    private function waitForOpcacheReady(
        $stdout,
        string $docker_image_name,
    ): void {
        for ($i = 0; $i < 20; $i++) {
            $line = fgets($stdout);
            if ($line === false) {
                $this->fail(
                    'unexpected EOF from container '
                    . $docker_image_name,
                );
            }
            if ($line === "ready\n") {
                return;
            }
            if ($line === "opcache_disabled\n") {
                $this->markTestSkipped(
                    'opcache not available in '
                    . $docker_image_name,
                );
            }
            // skip warning/notice lines
        }
        $this->fail('did not receive ready signal');
    }

    /**
     * Like waitForOpcacheReady but also captures the used_memory
     * line emitted before "ready".
     * @param resource $stdout
     */
    private function waitForOpcacheReadyWithUsage(
        $stdout,
        string $docker_image_name,
    ): string {
        $last_numeric = '0';
        for ($i = 0; $i < 20; $i++) {
            $line = fgets($stdout);
            if ($line === false) {
                $this->fail(
                    'unexpected EOF from container '
                    . $docker_image_name,
                );
            }
            if ($line === "ready\n") {
                return $last_numeric;
            }
            if ($line === "opcache_disabled\n") {
                $this->markTestSkipped(
                    'opcache not available in '
                    . $docker_image_name,
                );
            }
            if (is_numeric(trim($line))) {
                $last_numeric = $line;
            }
        }
        $this->fail('did not receive ready signal');
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
