<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\PhpProcessReader;

use FFI\CPointer;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Lib\PhpInternals\ZendTypeCData;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\PhpInternals\ZendTypeReaderCreator;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;
use PhpProfiler\Lib\Process\ProcessSpecifier;

final class PhpVersionDetector
{
    private const VERSION_STRING_CONVERTOR = [
        '7.0' => ZendTypeReader::V70,
        '7.1' => ZendTypeReader::V71,
        '7.2' => ZendTypeReader::V72,
        '7.3' => ZendTypeReader::V73,
        '7.4' => ZendTypeReader::V74,
        '8.0' => ZendTypeReader::V80,
        '8.1' => ZendTypeReader::V81,
    ];


    public function __construct(
        private PhpSymbolReaderCreator $php_symbol_reader_creator,
        private ZendTypeReaderCreator $zend_type_reader_creator,
        private MemoryReaderInterface $memory_reader,
    ) {
    }

    /** @return null|value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> */
    public function tryDetection(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
    ): ?string {
        try {
            $php_symbol_reader = $this->php_symbol_reader_creator->create(
                $process_specifier->pid,
                $target_php_settings->getDelimitedPhpRegex(),
                $target_php_settings->getDelimitedLibPthreadRegex(),
                $target_php_settings->php_path,
                $target_php_settings->libpthread_path,
            );
            $basic_functions_module = $php_symbol_reader->read('basic_functions_module')
                ?? throw new \Exception();

            // use default version for reading the definition of zend_module_entry
            $zend_type_reader = $this->zend_type_reader_creator->create(
                $target_php_settings->php_version
            );

            /** @var ZendTypeCData<\FFI\PhpInternals\zend_module_entry> $module_entry */
            $module_entry = $zend_type_reader->readAs('zend_module_entry', $basic_functions_module);
            /** @var CPointer $version_string_pointer */
            $version_string_pointer = \FFI::cast('long', $module_entry->typed->version)
                ?? throw new \Exception();
            $version_string_cdata = $this->memory_reader->read(
                $process_specifier->pid,
                $version_string_pointer->cdata,
                3
            );
            $php_version = \FFI::string($version_string_cdata, 3);
            var_dump($php_version);
        } catch (\Throwable $e) {
            var_dump($e->getMessage());
            return null;
        }
        return self::VERSION_STRING_CONVERTOR[$php_version] ?? null;
    }
}
