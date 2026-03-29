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
            new VariableValueTrigger('global::gcount:gt:0'),
            new VariableValueTrigger('global::gname:eq:test'),
            new VariableValueTrigger('global::gcache:count_gt:50'),
            new VariableValueTrigger('global::gflag:eq:true'),
            new VariableValueTrigger('global::gnull:is_null'),
            new VariableValueTrigger('global::nonexistent:gt:0'),
        ];
        $results = $variable_reader->readVariables(
            $triggers,
            $process_specifier,
            $target_php_settings,
            $eg_address,
        );

        // Integer
        $this->assertArrayHasKey('global::gcount', $results);
        $this->assertSame(
            VariableValue::TYPE_LONG,
            $results['global::gcount']->type,
        );
        $this->assertSame(99, $results['global::gcount']->scalar_value);

        // String
        $this->assertArrayHasKey('global::gname', $results);
        $this->assertSame(
            VariableValue::TYPE_STRING,
            $results['global::gname']->type,
        );
        $this->assertSame(
            'test_global',
            $results['global::gname']->scalar_value,
        );

        // Array count
        $this->assertArrayHasKey('global::gcache', $results);
        $this->assertSame(
            VariableValue::TYPE_ARRAY,
            $results['global::gcache']->type,
        );
        $this->assertSame(100, $results['global::gcache']->array_count);

        // Bool
        $this->assertArrayHasKey('global::gflag', $results);
        $this->assertSame(
            VariableValue::TYPE_BOOL,
            $results['global::gflag']->type,
        );
        $this->assertTrue($results['global::gflag']->scalar_value);

        // Null
        $this->assertArrayHasKey('global::gnull', $results);
        $this->assertSame(
            VariableValue::TYPE_NULL,
            $results['global::gnull']->type,
        );

        // Nonexistent
        $this->assertArrayNotHasKey(
            'global::nonexistent',
            $results,
        );
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
