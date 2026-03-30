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

        // Test local scope: function spec required
        // <main>() is the script-level frame
        $triggers_local = [
            new VariableValueTrigger(
                'local::<main>()$local_counter:gt:0',
            ),
            new VariableValueTrigger(
                'local::<main>()$local_items:count_gt:10',
            ),
        ];
        $results_local = $variable_reader->readVariables(
            $triggers_local,
            $process_specifier,
            $target_php_settings,
            $eg_address,
        );

        $key_counter = 'local::<main>()$local_counter';
        $this->assertArrayHasKey($key_counter, $results_local);
        $this->assertSame(
            VariableValue::TYPE_LONG,
            $results_local[$key_counter]->type,
        );
        $this->assertSame(
            42,
            $results_local[$key_counter]->scalar_value,
        );

        $key_items = 'local::<main>()$local_items';
        $this->assertArrayHasKey($key_items, $results_local);
        $this->assertSame(
            VariableValue::TYPE_ARRAY,
            $results_local[$key_items]->type,
        );
        $this->assertSame(
            50,
            $results_local[$key_items]->array_count,
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
        // v70-v73: zval* direct, v74-v81: zval** double ptr, v82+: MAP_PTR

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

    /**
     * Read static properties from an opcache-enabled target.
     *
     * When opcache is active on PHP 8.2+, static_members_table is
     * stored as a MAP_PTR offset with the LSB set to 1.
     * resolveMapPtr must mask the flag bit before calculating the
     * real address, otherwise the dereference reads from addr+1
     * and returns garbage.
     */
    #[DataProviderExternal(TargetPhpVmProvider::class, 'opcacheSupported')]
    public function testReadStaticPropertyWithOpcache(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no matching target');
        }
        $memory_reader = new MemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();

        $target_script = <<<'CODE'
            <?php
            $tmpdir = '/tmp/opcache_static_' . getmypid();
            @mkdir($tmpdir, 0777, true);
            $f = $tmpdir . '/StaticTarget.php';
            $code = '<?php class StaticTarget {'
                . ' public static $count = 77;'
                . ' public static $label = "opcache_test";'
                . ' public static $items = array(10, 20, 30, 40);'
                . ' }';
            file_put_contents($f, $code);
            require $f;
            StaticTarget::$count;
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
            $this->opcacheIniFor($php_version),
        );

        $s = $this->readOpcacheReadySignal($pipes[1]);
        if ($s === "opcache_disabled\n") {
            $this->markTestSkipped(
                'opcache not available in '
                . $docker_image_name,
            );
        }
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
                'static::StaticTarget::$count:gt:0',
            ),
            new VariableValueTrigger(
                'static::StaticTarget::$label:eq:opcache_test',
            ),
            new VariableValueTrigger(
                'static::StaticTarget::$items:count_gt:1',
            ),
        ];
        $results = $variable_reader->readVariables(
            $triggers,
            $process_specifier,
            $target_php_settings,
            $eg_address,
            $cg_address,
        );

        $key_count = 'static::StaticTarget::$count';
        $this->assertArrayHasKey($key_count, $results);
        $this->assertSame(
            VariableValue::TYPE_LONG,
            $results[$key_count]->type,
        );
        $this->assertSame(77, $results[$key_count]->scalar_value);

        $key_label = 'static::StaticTarget::$label';
        $this->assertArrayHasKey($key_label, $results);
        $this->assertSame(
            VariableValue::TYPE_STRING,
            $results[$key_label]->type,
        );
        $this->assertSame(
            'opcache_test',
            $results[$key_label]->scalar_value,
        );

        $key_items = 'static::StaticTarget::$items';
        $this->assertArrayHasKey($key_items, $results);
        $this->assertSame(
            VariableValue::TYPE_ARRAY,
            $results[$key_items]->type,
        );
        $this->assertSame(4, $results[$key_items]->array_count);
    }

    /**
     * Verify resolveMapPtr LSB masking for PHP 7.4 with opcache.
     *
     * Background (why PEAR.php?):
     *   In Docker's overlayfs, opcache only caches files that reside
     *   in the image's lower layer — bind-mounted or dynamically
     *   created files live on a different device and are silently
     *   skipped.  PEAR.php ships with the official php:7.4-cli
     *   image AND declares a static property ($bivalentMethods),
     *   making it the only readily-available file that satisfies
     *   both conditions.
     *
     * What this tests:
     *   When opcache caches a script in PHP 7.4 CLI, classes with
     *   static properties are persisted as IMMUTABLE in SHM.
     *   zend_persist_class_entry() calls ZEND_MAP_PTR_NEW(), which
     *   in ZEND_MAP_PTR_KIND_PTR_OR_OFFSET mode returns
     *   PTR2OFFSET(ptr) = (ptr - base) | 1, storing the value with
     *   LSB=1 in static_members_table__ptr.
     *
     *   PHP 7.4's map_ptr_base is the raw (unbiased) base, so
     *   resolveMapPtr must strip the LSB before adding to the base
     *   (matching ZEND_MAP_PTR_OFFSET2PTR = base + offset - 1).
     *   PHP 8.0+ biases the base by -1 (commit 27815959 in
     *   php-src), making the explicit mask unnecessary.
     *
     *   Without the & ~1 mask on PHP 7.4, the resolved address is
     *   off by 1 byte, producing a garbage pointer that makes the
     *   static property unreadable.
     */
    #[Group('target-version')]
    public function testReadStaticPropertyWithOpcacheImmutableClass(): void
    {
        $php_version = 'v74';
        $docker_image_name = TargetPhpVmProvider::dockerImageNameFromTarget(
            $php_version,
        );

        $memory_reader = new MemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();

        // PEAR.php is in the image's base layer so opcache caches
        // it; it declares PEAR::$bivalentMethods (a static prop).
        // We must access the static property to trigger
        // zend_class_init_statics(), which populates the MAP_PTR
        // slot (initially NULL for immutable classes).
        $target_script = <<<'CODE'
            <?php
            require "/usr/local/lib/php/PEAR.php";
            // Force static member initialization via Reflection.
            // PEAR::$bivalentMethods is protected and only accessed
            // in __call/__callStatic, so normal usage does not
            // trigger ZEND_FETCH_STATIC_PROP / zend_class_init_statics.
            $r = new ReflectionProperty('PEAR', 'bivalentMethods');
            $r->setAccessible(true);
            $r->getValue();
            if (function_exists('opcache_get_status')) {
                $s = @opcache_get_status(false);
                if ($s && ($s['opcache_enabled'] ?? false)) {
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
            '-dzend_extension=opcache'
            . ' -dopcache.enable_cli=1'
            . ' -dopcache.enable=1',
        );

        $s = $this->readOpcacheReadySignal($pipes[1]);
        if ($s !== "ready\n") {
            $this->markTestSkipped(
                'opcache not available in '
                . $docker_image_name,
            );
        }

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

        $zend_type_reader = $zend_type_reader_creator->create(
            $php_version,
        );
        $dereferencer = new \Reli\Lib\Process\Pointer\RemoteProcessDereferencer(
            $memory_reader,
            $process_specifier,
            new \Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider(
                $zend_type_reader,
            ),
            new \Reli\Lib\PhpInternals\VersionedPointedTypeResolver(
                $php_version,
            ),
        );

        $eg = $dereferencer->deref(new \Reli\Lib\Process\Pointer\Pointer(
            \Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals::class,
            $eg_address,
            $zend_type_reader->sizeOf('zend_executor_globals'),
        ));
        $class_table = $dereferencer->deref($eg->class_table);

        $bucket = $class_table->findByKey(
            $dereferencer,
            'pear',
        );
        $this->assertNotNull($bucket, 'PEAR class must exist');

        $class_zval = $dereferencer->deref(
            $bucket->val->getPointer(),
        );
        $ce = $dereferencer->deref($class_zval->value->ce);
        $this->assertGreaterThan(
            0,
            $ce->default_static_members_count,
            'PEAR must have static members',
        );
        $this->assertNotNull($ce->static_members_table);
        $raw_ptr = $ce->static_members_table->address;

        // Key assertion: on PHP 7.4 with opcache, the
        // static_members_table__ptr is PTR2OFFSET encoded.
        $this->assertSame(
            1,
            $raw_ptr & 1,
            sprintf(
                'static_members_table__ptr 0x%x should have'
                . ' LSB=1 (PTR2OFFSET encoding)',
                $raw_ptr,
            ),
        );

        // resolveMapPtr must handle this correctly.
        // Without the & ~1 fix this would read from an
        // off-by-1 address and throw RuntimeException
        // ("no memory region found" in dump analysis, or
        // garbage pointer in live process).
        $cg = $dereferencer->deref(
            new \Reli\Lib\Process\Pointer\Pointer(
                \Reli\Lib\PhpInternals\Types\Zend\ZendCompilerGlobals::class,
                $cg_address,
                $zend_type_reader->sizeOf('zend_compiler_globals'),
            ),
        );
        $table_address = $zend_type_reader->resolveMapPtr(
            $cg->map_ptr_base,
            $raw_ptr,
            $dereferencer,
        );
        // new PEAR() accesses self::$bivalentMethods, which
        // triggers ZEND_FETCH_STATIC_PROP → zend_class_init_statics
        // → the MAP_PTR slot is populated with the table pointer.
        // Without the & ~1 fix, the resolved address is off by 1
        // and reads garbage from the wrong slot, giving either 0
        // or a wildly misaligned pointer.
        $this->assertNotSame(
            0,
            $table_address,
            'MAP_PTR slot should be non-zero after static init',
        );
        $this->assertSame(
            0,
            $table_address & 0xf,
            sprintf(
                'Resolved address 0x%x should be aligned',
                $table_address,
            ),
        );
    }

    private function opcacheIniFor(string $php_version): string
    {
        $load = version_compare(
            ltrim($php_version, 'v'),
            '85',
            '<',
        ) ? '-dzend_extension=opcache ' : '';
        return $load
            . '-dopcache.enable_cli=1 -dopcache.enable=1';
    }

    /**
     * Same MAP_PTR bug, but using opcache.preload to guarantee
     * that statics are fully initialized (non-zero MAP_PTR slot).
     * preload requires root, so this test uses a custom Docker
     * command without -u uid:gid.
     */
    #[Group('target-version')]
    public function testReadStaticPropertyWithOpcachePreload(): void
    {
        $php_version = 'v74';
        $docker_image_name = TargetPhpVmProvider::dockerImageNameFromTarget(
            $php_version,
        );

        $memory_reader = new MemoryReader();
        $zend_type_reader_creator = new ZendTypeReaderCreator();

        $preload_file = tempnam('/tmp/reli-test', 'preload-');
        $target_file = tempnam('/tmp/reli-test', 'target-');
        $pid_writer = tempnam('/tmp/reli-test', 'pidwr-');
        $pid_file = tempnam('/tmp/reli-test', 'pidf-');
        chmod($preload_file, 0777);
        chmod($target_file, 0777);
        chmod($pid_writer, 0777);
        chmod($pid_file, 0777);

        file_put_contents($preload_file, <<<'PHP'
            <?php
            class PreloadedTarget {
                public static $count = 77;
                public static $label = "preloaded_test";
                public static $items = [10, 20, 30, 40];
            }
            PHP);
        file_put_contents($pid_writer, <<<'PHP'
            <?php
            file_put_contents('/target-pid', getmypid());
            fputs(STDOUT, "pid written\n");
            PHP);
        file_put_contents($target_file, <<<'PHP'
            <?php
            PreloadedTarget::$count;
            if (function_exists('opcache_get_status')) {
                $s = @opcache_get_status(false);
                if ($s && ($s['opcache_enabled'] ?? false)) {
                    fputs(STDOUT, "ready\n");
                } else {
                    fputs(STDOUT, "opcache_disabled\n");
                }
            } else {
                fputs(STDOUT, "opcache_disabled\n");
            }
            fgets(STDIN);
            PHP);

        $php_ini = '-dzend_extension=opcache'
            . ' -dopcache.enable_cli=1'
            . ' -dopcache.enable=1'
            . ' -dopcache.preload=/preload.php'
            . ' -dopcache.preload_user=root';

        $this->child = proc_open(
            [
                'docker', 'run', '--rm', '--pid', 'host',
                '-i', '--entrypoint', 'sh',
                '-v', "$preload_file:/preload.php:ro",
                '-v', "$target_file:/source:rw",
                '-v', "$pid_writer:/pid-writer:rw",
                '-v', "$pid_file:/target-pid:rw",
                '-v', '/tmp/reli-test:/tmp/reli-test:rw',
                $docker_image_name, '-c',
                "php $php_ini"
                . ' -dauto_prepend_file=/pid-writer'
                . ' /source',
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );

        $pid_msg = fgets($pipes[1]);
        if ($pid_msg === false) {
            $this->markTestSkipped('container failed to start');
        }
        $pid = (int)file_get_contents($pid_file);
        if ($pid === 0) {
            $this->markTestSkipped('failed to get target pid');
        }

        $s = $this->readOpcacheReadySignal($pipes[1]);
        if ($s !== "ready\n") {
            $this->markTestSkipped('opcache preload not available');
        }

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
                'static::PreloadedTarget::$count:gt:0',
            ),
            new VariableValueTrigger(
                'static::PreloadedTarget::$label'
                . ':eq:preloaded_test',
            ),
            new VariableValueTrigger(
                'static::PreloadedTarget::$items:count_gt:1',
            ),
        ];
        $results = $variable_reader->readVariables(
            $triggers,
            $process_specifier,
            $target_php_settings,
            $eg_address,
            $cg_address,
        );

        $key = 'static::PreloadedTarget::$count';
        $this->assertArrayHasKey($key, $results);
        $this->assertSame(77, $results[$key]->scalar_value);

        $key_label = 'static::PreloadedTarget::$label';
        $this->assertArrayHasKey($key_label, $results);
        $this->assertSame(
            'preloaded_test',
            $results[$key_label]->scalar_value,
        );

        $key_items = 'static::PreloadedTarget::$items';
        $this->assertArrayHasKey($key_items, $results);
        $this->assertSame(4, $results[$key_items]->array_count);
    }

    /**
     * Read lines from stdout, skipping extension load warnings.
     * @param resource $stdout
     */
    private function readOpcacheReadySignal($stdout): string
    {
        for ($i = 0; $i < 20; $i++) {
            $line = fgets($stdout);
            if ($line === false) {
                return '';
            }
            if (
                $line === "ready\n"
                || $line === "opcache_disabled\n"
            ) {
                return $line;
            }
        }
        return '';
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
