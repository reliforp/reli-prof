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

namespace Reli\Lib\PhpProcessReader;

use Reli\BaseTestCase;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\LinkMapLoader;
use Reli\Lib\Elf\Process\PerBinarySymbolCacheRetriever;
use Reli\Lib\Elf\Process\ProcessModuleSymbolReaderCreator;
use Reli\Lib\Elf\SymbolResolver\Elf64SymbolResolverCreator;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprintCreator;
use Reli\Lib\File\CatFileReader;
use Reli\Lib\File\PathResolver\ContainerAwarePathResolver;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreator;
use Reli\Lib\Process\MemoryReader\MemoryReader;
use Reli\Lib\Process\ProcessSpecifier;

class PhpGlobalsFinderTest extends BaseTestCase
{
    /** @var resource|false */
    private $child;

    public function testFindTsrmLsCacheCachesNegativeOnlyForNtsBinaries(): void
    {
        // The host PHP under test is NTS (non-ZTS), so the libphp/php binary
        // contains no PT_TLS segment. findByBruteForcing should report
        // has_tls_segment=false and PhpGlobalsFinder should persist the
        // negative entry so that the next attach skips the (cheap, but not
        // free) ELF parse. This is the only case where caching the negative
        // is correct -- ZTS targets are covered by the FrankenPHP
        // integration test in tests/Lib/PhpProcessReader/CallTraceReader/.
        $memory_reader = new MemoryReader();
        $this->child = proc_open(
            [
                PHP_BINARY,
                '-r',
                'fputs(STDOUT, "a\n");fgets(STDIN);'
            ],
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w']
            ],
            $pipes
        );

        fgets($pipes[1]);
        $child_status = proc_get_status($this->child);

        $cache_dir = sys_get_temp_dir() . '/reli-test-' . uniqid();
        $binary_analysis_cache = new BinaryAnalysisCache($cache_dir);
        $php_globals_finder = self::buildPhpGlobalsFinder(
            $memory_reader,
            $binary_analysis_cache,
        );

        $tsrm_ls_cache = $php_globals_finder->findTsrmLsCache(
            new ProcessSpecifier($child_status['pid']),
            new TargetPhpSettings(
                php_version: \Reli\Lib\PhpInternals\ZendTypeReader::V84,
            ),
        );
        $this->assertNull($tsrm_ls_cache, 'Host PHP is NTS, so TSRM resolution must return null');

        // Confirm the negative was persisted: a fresh PhpGlobalsFinder reading
        // the same cache should short-circuit without doing brute force.
        $negative_was_persisted = false;
        foreach (glob($cache_dir . '/*/tsrm_ls_cache.*') ?: [] as $cache_file) {
            $content = @file_get_contents($cache_file);
            if ($content === false) {
                continue;
            }
            if (str_ends_with($cache_file, '.igbinary')) {
                /** @psalm-suppress UndefinedFunction */
                $data = igbinary_unserialize($content);
            } else {
                $data = json_decode($content, true);
            }
            if (is_array($data) && ($data['has_tsrm_ls_cache'] ?? null) === false) {
                $negative_was_persisted = true;
                break;
            }
        }
        $this->assertTrue(
            $negative_was_persisted,
            'NTS targets must persist has_tsrm_ls_cache=false to skip the ELF parse on subsequent attaches',
        );
    }

    public function testFindModuleRegistry()
    {
        $memory_reader = new MemoryReader();
        $this->child = proc_open(
            [
                PHP_BINARY,
                '-r',
                'fputs(STDOUT, "a\n");fgets(STDIN);'
            ],
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w']
            ],
            $pipes
        );

        fgets($pipes[1]);
        $child_status = proc_get_status($this->child);

        $binary_analysis_cache = new BinaryAnalysisCache(sys_get_temp_dir() . '/reli-test-' . uniqid());
        $php_globals_finder = self::buildPhpGlobalsFinder($memory_reader, $binary_analysis_cache);
        $module_registry = $php_globals_finder->findModuleRegistry(
            new ProcessSpecifier($child_status['pid']),
            new TargetPhpSettings()
        );
        $this->assertIsInt($module_registry);
    }

    private static function buildPhpGlobalsFinder(
        MemoryReader $memory_reader,
        BinaryAnalysisCache $binary_analysis_cache,
    ): PhpGlobalsFinder {
        $php_symbol_reader_creator = new PhpSymbolReaderCreator(
            new ProcessModuleSymbolReaderCreator(
                new Elf64SymbolResolverCreator(
                    new CatFileReader(),
                    new Elf64Parser(
                        new LittleEndianReader()
                    ),
                ),
                $memory_reader,
                new PerBinarySymbolCacheRetriever(),
                new LittleEndianReader(),
                new LinkMapLoader(
                    $memory_reader,
                    new LittleEndianReader(),
                ),
                new ContainerAwarePathResolver(),
                $binary_analysis_cache,
            ),
            ProcessMemoryMapCreator::create(),
            $binary_analysis_cache,
        );
        $memory_reader_for_finder = new MemoryReader();
        $integer_reader = new LittleEndianReader();
        $process_memory_map_creator = ProcessMemoryMapCreator::create();
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
        return new PhpGlobalsFinder(
            $php_symbol_reader_creator,
            $integer_reader,
            $memory_reader_for_finder,
            $tsrm_ls_cache_finder,
            $tsrm_globals_resolver,
            $binary_analysis_cache,
            $process_memory_map_creator,
            $binary_fingerprint_creator,
        );
    }
}
