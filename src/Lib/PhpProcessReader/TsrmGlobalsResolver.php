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

use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\ByteStream\CDataByteReader;
use Reli\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprint;
use Reli\Lib\Elf\Process\ProcessSymbolReaderInterface;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
use Reli\Lib\Process\MemoryMap\ProcessModuleMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\ProcessSpecifier;
use RuntimeException;

final class TsrmGlobalsResolver
{
    public function __construct(
        private PhpSymbolReaderCreator $php_symbol_reader_creator,
        private IntegerByteSequenceReader $integer_reader,
        private MemoryReaderInterface $memory_reader,
        private BinaryAnalysisCache $binary_analysis_cache,
        private ProcessMemoryMapCreatorInterface $process_memory_map_creator,
    ) {
    }

    public function getZtsGlobalsSymbolReader(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings
    ): ProcessSymbolReaderInterface {
        return $this->php_symbol_reader_creator->create(
            $process_specifier->pid,
            $target_php_settings->zts_globals_regex,
            $target_php_settings->libpthread_regex,
            $target_php_settings->php_path,
            $target_php_settings->libpthread_path
        );
    }

    public function resolveGlobalsAddress(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        string $symbol_name,
        int $tsrm_ls_cache,
    ): int {
        $fingerprint = $this->getFingerprint($process_specifier, $target_php_settings);
        $cached = $this->binary_analysis_cache->get($fingerprint, 'zts_globals');

        if ($cached !== null && isset($cached[$symbol_name]) && is_array($cached[$symbol_name])) {
            $entry = $cached[$symbol_name];
            if (
                isset($entry['method'], $entry['value'])
                && is_string($entry['method'])
                && is_int($entry['value'])
            ) {
                if ($entry['method'] === 'offset') {
                    return $tsrm_ls_cache + $entry['value'];
                }
                if ($entry['method'] === 'id') {
                    return $this->resolveById($process_specifier, $tsrm_ls_cache, $entry['value']);
                }
            }
        }

        switch ($target_php_settings->php_version) {
            case ZendTypeReader::V70:
            case ZendTypeReader::V71:
            case ZendTypeReader::V72:
            case ZendTypeReader::V73:
                return $this->resolveByIdSymbol(
                    $process_specifier,
                    $target_php_settings,
                    $symbol_name,
                    $tsrm_ls_cache,
                    $fingerprint,
                    $cached,
                );

            case ZendTypeReader::V74:
            case ZendTypeReader::V80:
            case ZendTypeReader::V81:
            case ZendTypeReader::V82:
            case ZendTypeReader::V83:
            case ZendTypeReader::V84:
                $offset_symbol = $symbol_name . '_offset';
                $globals_offset_cdata = $this->getZtsGlobalsSymbolReader(
                    $process_specifier,
                    $target_php_settings
                )->read($offset_symbol);
                if (!is_null($globals_offset_cdata)) {
                    $globals_offset = $this->integer_reader->read64(
                        new CDataByteReader($globals_offset_cdata),
                        0
                    )->toInt();
                    if ($globals_offset !== 0) {
                        $this->cacheEntry($fingerprint, $cached, $symbol_name, 'offset', $globals_offset);
                        return $tsrm_ls_cache + $globals_offset;
                    }
                }
                // Fallback to _id approach when _offset is 0 or not found
                // (e.g. libphp.so built as PIC where ZEND_ENABLE_STATIC_TSRMLS_CACHE is not defined)
                return $this->resolveByIdSymbol(
                    $process_specifier,
                    $target_php_settings,
                    $symbol_name,
                    $tsrm_ls_cache,
                    $fingerprint,
                    $cached,
                );
            default:
                throw new \LogicException('this should never happen');
        }
    }

    private function resolveById(
        ProcessSpecifier $process_specifier,
        int $tsrm_ls_cache,
        int $globals_id,
    ): int {
        $tsrm_ls_cache_dereferenced = $this->integer_reader->read64(
            new CDataByteReader(
                $this->memory_reader->read(
                    $process_specifier->pid,
                    $tsrm_ls_cache,
                    8
                )
            ),
            0
        )->toInt();
        return $this->integer_reader->read64(
            new CDataByteReader(
                $this->memory_reader->read(
                    $process_specifier->pid,
                    $tsrm_ls_cache_dereferenced + ($globals_id - 1) * 8,
                    8
                )
            ),
            0
        )->toInt();
    }

    /** @param array<array-key, mixed>|null $cached */
    private function resolveByIdSymbol(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        string $symbol_name,
        int $tsrm_ls_cache,
        BinaryFingerprint $fingerprint,
        ?array $cached,
    ): int {
        $id_symbol = $symbol_name . '_id';
        $globals_id_cdata = $this->getZtsGlobalsSymbolReader($process_specifier, $target_php_settings)
            ->read($id_symbol);
        if (is_null($globals_id_cdata)) {
            throw new RuntimeException('globals offset and id not found for ' . $symbol_name);
        }
        $globals_id = $this->integer_reader->read32(
            new CDataByteReader($globals_id_cdata),
            0
        );
        $this->cacheEntry($fingerprint, $cached, $symbol_name, 'id', $globals_id);
        return $this->resolveById($process_specifier, $tsrm_ls_cache, $globals_id);
    }

    /** @param array<array-key, mixed>|null $cached */
    private function cacheEntry(
        BinaryFingerprint $fingerprint,
        ?array $cached,
        string $symbol_name,
        string $method,
        int $value,
    ): void {
        $cache_data = $cached ?? [];
        $cache_data[$symbol_name] = ['method' => $method, 'value' => $value];
        $this->binary_analysis_cache->set($fingerprint, 'zts_globals', $cache_data);
    }

    private function getFingerprint(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
    ): BinaryFingerprint {
        $process_memory_map = $this->process_memory_map_creator->getProcessMemoryMap(
            $process_specifier->pid,
        );
        $php_areas = $process_memory_map->findByNameRegex($target_php_settings->zts_globals_regex);
        $module_map = new ProcessModuleMemoryMap($php_areas);
        return BinaryFingerprint::fromProcessModuleMemoryMap($module_map);
    }
}
