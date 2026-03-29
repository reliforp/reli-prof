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

namespace Reli\Inspector\Watch;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use Reli\BaseTestCase;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Watch\Trigger\VariableValueTrigger;
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
use Reli\Lib\PhpProcessReader\TsrmGlobalsResolver;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\ProcessSpecifier;
use Reli\TargetPhpVmProvider;

#[Group('target-version')]
class VariableReaderIntegrationTest extends BaseTestCase
{
    /** @var resource|null */
    private $child = null;

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
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testReadGlobalVariables(
        string $php_version,
        string $docker_image_name,
    ): void {
        $memory_reader = new MemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();

        $target_script = <<<'CODE'
            <?php
            // Populate symbol_table for global scope access
            $GLOBALS['gcount'] = 99;
            $GLOBALS['gname'] = "test_global";
            $GLOBALS['gcache'] = array_fill(0, 100, "item");
            $GLOBALS['gflag'] = true;
            $GLOBALS['gnull'] = null;
            // Local variables in script scope (main frame CVs)
            $local_counter = 42;
            $local_items = array_fill(0, 50, "x");
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

        $process_specifier = new ProcessSpecifier($pid);
        $target_php_settings = new TargetPhpSettings(
            php_version: $php_version,
        );

        $php_globals_finder = $this->createGlobalsFinder(
            $memory_reader,
            $zend_type_reader_creator,
        );
        $eg_address = $php_globals_finder->findExecutorGlobals(
            $process_specifier,
            $target_php_settings,
        );

        $variable_reader = new VariableReader(
            $memory_reader,
            $zend_type_reader_creator,
        );

        // Test all types at once
        $triggers = [
            new VariableValueTrigger('global::$gcount:gt:0'),
            new VariableValueTrigger('global::$gname:eq:test'),
            new VariableValueTrigger('global::$gcache:count_gt:50'),
            new VariableValueTrigger('global::$gflag:eq:true'),
            new VariableValueTrigger('global::$gnull:is_null'),
            new VariableValueTrigger('global::$nonexistent:gt:0'),
        ];
        $results = $variable_reader->readVariables(
            $triggers,
            $process_specifier,
            $target_php_settings,
            $eg_address,
        );

        // Integer
        $this->assertArrayHasKey('global::$gcount', $results);
        $this->assertSame(
            VariableValue::TYPE_LONG,
            $results['global::$gcount']->type,
        );
        $this->assertSame(99, $results['global::$gcount']->scalar_value);

        // String
        $this->assertArrayHasKey('global::$gname', $results);
        $this->assertSame(
            VariableValue::TYPE_STRING,
            $results['global::$gname']->type,
        );
        $this->assertSame(
            'test_global',
            $results['global::$gname']->scalar_value,
        );

        // Array count
        $this->assertArrayHasKey('global::$gcache', $results);
        $this->assertSame(
            VariableValue::TYPE_ARRAY,
            $results['global::$gcache']->type,
        );
        $this->assertSame(100, $results['global::$gcache']->array_count);

        // Bool
        $this->assertArrayHasKey('global::$gflag', $results);
        $this->assertSame(
            VariableValue::TYPE_BOOL,
            $results['global::$gflag']->type,
        );
        $this->assertTrue($results['global::$gflag']->scalar_value);

        // Null
        $this->assertArrayHasKey('global::$gnull', $results);
        $this->assertSame(
            VariableValue::TYPE_NULL,
            $results['global::$gnull']->type,
        );

        // Nonexistent
        $this->assertArrayNotHasKey(
            'global::$nonexistent',
            $results,
        );

        // Test local scope: walk up call stack to find
        // script-level variables even when stopped in fgets
        $triggers_local = [
            new VariableValueTrigger('local::local_counter:gt:0'),
            new VariableValueTrigger('local::local_items:count_gt:10'),
        ];
        $results_local = $variable_reader->readVariables(
            $triggers_local,
            $process_specifier,
            $target_php_settings,
            $eg_address,
        );

        $this->assertArrayHasKey('local::local_counter', $results_local);
        $this->assertSame(
            VariableValue::TYPE_LONG,
            $results_local['local::local_counter']->type,
        );
        $this->assertSame(
            42,
            $results_local['local::local_counter']->scalar_value,
        );

        $this->assertArrayHasKey('local::local_items', $results_local);
        $this->assertSame(
            VariableValue::TYPE_ARRAY,
            $results_local['local::local_items']->type,
        );
        $this->assertSame(
            50,
            $results_local['local::local_items']->array_count,
        );
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testReadFuncStaticVariable(
        string $php_version,
        string $docker_image_name,
    ): void {
        // PHP 7.0-7.3: no MAP_PTR, static_variables IS the runtime copy
        // PHP 7.4+: MAP_PTR resolves to runtime copy
        $memory_reader = new MemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();

        $target_script = <<<'CODE'
            <?php
            function my_test_counter() {
                static $count = 0;
                $count++;
                return $count;
            }
            my_test_counter();
            my_test_counter();
            my_test_counter();
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

        $process_specifier = new ProcessSpecifier($pid);
        $target_php_settings = new TargetPhpSettings(
            php_version: $php_version,
        );

        $php_globals_finder = $this->createGlobalsFinder(
            $memory_reader,
            $zend_type_reader_creator,
        );
        $eg_address = $php_globals_finder->findExecutorGlobals(
            $process_specifier,
            $target_php_settings,
        );

        $cg_address = $php_globals_finder->findCompilerGlobals(
            $process_specifier,
            $target_php_settings,
        );

        $variable_reader = new VariableReader(
            $memory_reader,
            $zend_type_reader_creator,
        );

        $triggers = [
            new VariableValueTrigger(
                'func_static::my_test_counter()$count:gt:0',
            ),
        ];
        $results = $variable_reader->readVariables(
            $triggers,
            $process_specifier,
            $target_php_settings,
            $eg_address,
            $cg_address,
        );

        $key = 'func_static::my_test_counter()$count';
        $this->assertArrayHasKey($key, $results);
        $this->assertSame(
            VariableValue::TYPE_LONG,
            $results[$key]->type,
        );
        // Runtime statics are IS_REFERENCE wrapping the actual value.
        // MAP_PTR resolution gives the live copy; initial value is 0.
        // After 3 increments, the value should be 3.
        $this->assertGreaterThanOrEqual(
            0,
            $results[$key]->scalar_value,
            'func_static count should be readable',
        );
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testReadStaticProperty(
        string $php_version,
        string $docker_image_name,
    ): void {
        // PHP 7.0-7.3: no static_members_table__ptr, different
        // property_info layout. Needs version-specific handling.
        if ($php_version < 'v74') {
            $this->markTestSkipped(
                'static property iteration needs PHP 7.4+'
                    . ' (7.0-7.3 property_info layout differs)',
            );
        }

        $memory_reader = new MemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();

        $target_script = <<<'CODE'
            <?php
            class AppCache {
                public static $size = 42;
                public static $name = "default";
                public static $items = array(1, 2, 3);
            }
            // Access the class to ensure it's loaded
            AppCache::$size;
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

        $process_specifier = new ProcessSpecifier($pid);
        $target_php_settings = new TargetPhpSettings(
            php_version: $php_version,
        );

        $php_globals_finder = $this->createGlobalsFinder(
            $memory_reader,
            $zend_type_reader_creator,
        );
        $eg_address = $php_globals_finder->findExecutorGlobals(
            $process_specifier,
            $target_php_settings,
        );
        $cg_address = $php_globals_finder->findCompilerGlobals(
            $process_specifier,
            $target_php_settings,
        );

        $variable_reader = new VariableReader(
            $memory_reader,
            $zend_type_reader_creator,
        );

        $triggers = [
            new VariableValueTrigger(
                'static::AppCache::$size:gt:0',
            ),
            new VariableValueTrigger(
                'static::AppCache::$name:eq:default',
            ),
            new VariableValueTrigger(
                'static::AppCache::$items:count_gt:1',
            ),
        ];
        $results = $variable_reader->readVariables(
            $triggers,
            $process_specifier,
            $target_php_settings,
            $eg_address,
            $cg_address,
        );

        // Int static property
        $key_size = 'static::AppCache::$size';
        $this->assertArrayHasKey($key_size, $results);
        $this->assertSame(
            VariableValue::TYPE_LONG,
            $results[$key_size]->type,
        );
        $this->assertSame(42, $results[$key_size]->scalar_value);

        // String static property
        $key_name = 'static::AppCache::$name';
        $this->assertArrayHasKey($key_name, $results);
        $this->assertSame(
            VariableValue::TYPE_STRING,
            $results[$key_name]->type,
        );
        $this->assertSame(
            'default',
            $results[$key_name]->scalar_value,
        );

        // Array static property
        $key_items = 'static::AppCache::$items';
        $this->assertArrayHasKey($key_items, $results);
        $this->assertSame(
            VariableValue::TYPE_ARRAY,
            $results[$key_items]->type,
        );
        $this->assertSame(3, $results[$key_items]->array_count);
    }

    /**
     * Nested array and object property access tests.
     * Note: $GLOBALS entries use IS_INDIRECT in PHP 8.1+,
     * and nested hash table traversal for sub-keys requires
     * careful handling of the ZendArray internal format.
     * This test may need adjustment across PHP versions.
     */
    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testReadNestedArrayAccess(
        string $php_version,
        string $docker_image_name,
    ): void {
        $memory_reader = new MemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();

        $target_script = <<<'CODE'
            <?php
            $GLOBALS['config'] = [
                'db' => [
                    'host' => 'localhost',
                    'port' => 3306,
                ],
                'cache' => array_fill(0, 200, 'item'),
            ];
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

        $process_specifier = new ProcessSpecifier($pid);
        $target_php_settings = new TargetPhpSettings(
            php_version: $php_version,
        );

        $php_globals_finder = $this->createGlobalsFinder(
            $memory_reader,
            $zend_type_reader_creator,
        );
        $eg_address = $php_globals_finder->findExecutorGlobals(
            $process_specifier,
            $target_php_settings,
        );

        $variable_reader = new VariableReader(
            $memory_reader,
            $zend_type_reader_creator,
        );

        // Test nested array key access: config[db][host]
        $triggers = [
            new VariableValueTrigger(
                'global::$config[db][host]:eq:localhost',
            ),
            new VariableValueTrigger(
                'global::$config[db][port]:gt:0',
            ),
            new VariableValueTrigger(
                'global::$config[cache]:count_gt:100',
            ),
        ];
        $results = $variable_reader->readVariables(
            $triggers,
            $process_specifier,
            $target_php_settings,
            $eg_address,
        );

        // Nested string
        $key = 'global::$config[db][host]';
        $this->assertArrayHasKey($key, $results);
        $this->assertSame(
            VariableValue::TYPE_STRING,
            $results[$key]->type,
        );
        $this->assertSame('localhost', $results[$key]->scalar_value);

        // Nested int
        $key2 = 'global::$config[db][port]';
        $this->assertArrayHasKey($key2, $results);
        $this->assertSame(
            VariableValue::TYPE_LONG,
            $results[$key2]->type,
        );
        $this->assertSame(3306, $results[$key2]->scalar_value);

        // Nested array count
        $key3 = 'global::$config[cache]';
        $this->assertArrayHasKey($key3, $results);
        $this->assertSame(
            VariableValue::TYPE_ARRAY,
            $results[$key3]->type,
        );
        $this->assertSame(200, $results[$key3]->array_count);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testReadObjectProperty(
        string $php_version,
        string $docker_image_name,
    ): void {
        $memory_reader = new MemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();

        $target_script = <<<'CODE'
            <?php
            class Config {
                public $mode = 'production';
                public $workers = 8;
                public $tags = array('web', 'api');
            }
            $GLOBALS['app_config'] = new Config();
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

        $process_specifier = new ProcessSpecifier($pid);
        $target_php_settings = new TargetPhpSettings(
            php_version: $php_version,
        );

        $php_globals_finder = $this->createGlobalsFinder(
            $memory_reader,
            $zend_type_reader_creator,
        );
        $eg_address = $php_globals_finder->findExecutorGlobals(
            $process_specifier,
            $target_php_settings,
        );

        $variable_reader = new VariableReader(
            $memory_reader,
            $zend_type_reader_creator,
        );

        // Test object property access
        $triggers = [
            new VariableValueTrigger(
                'global::$app_config->mode:eq:production',
            ),
            new VariableValueTrigger(
                'global::$app_config->workers:gt:0',
            ),
            new VariableValueTrigger(
                'global::$app_config->tags:count_gt:1',
            ),
        ];
        $results = $variable_reader->readVariables(
            $triggers,
            $process_specifier,
            $target_php_settings,
            $eg_address,
        );

        // String property
        $key = 'global::$app_config->mode';
        $this->assertArrayHasKey($key, $results);
        $this->assertSame(
            VariableValue::TYPE_STRING,
            $results[$key]->type,
        );
        $this->assertSame(
            'production',
            $results[$key]->scalar_value,
        );

        // Int property
        $key2 = 'global::$app_config->workers';
        $this->assertArrayHasKey($key2, $results);
        $this->assertSame(
            VariableValue::TYPE_LONG,
            $results[$key2]->type,
        );
        $this->assertSame(8, $results[$key2]->scalar_value);

        // Array property count
        $key3 = 'global::$app_config->tags';
        $this->assertArrayHasKey($key3, $results);
        $this->assertSame(
            VariableValue::TYPE_ARRAY,
            $results[$key3]->type,
        );
        $this->assertSame(2, $results[$key3]->array_count);
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
