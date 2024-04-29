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
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendModuleEntry;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Reli\Lib\Process\ProcessSpecifier;
use Webmozart\Assert\Assert;

class PhpVersionDetector
{
    private ?ZendTypeReader $zend_type_reader = null;

    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
    ) {
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    private function getTypeReader(string $php_version): ZendTypeReader
    {
        if (is_null($this->zend_type_reader)) {
            $this->zend_type_reader = $this->zend_type_reader_creator->create($php_version);
        }
        return $this->zend_type_reader;
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    private function getDereferencer(int $pid, string $php_version): Dereferencer
    {
        return new RemoteProcessDereferencer(
            $this->memory_reader,
            new ProcessSpecifier($pid),
            new ZendCastedTypeProvider(
                $this->getTypeReader($php_version),
            )
        );
    }

    /** @return TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> */
    public function decidePhpVersion(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
    ): TargetPhpSettings {
        if ($target_php_settings->php_version !== 'auto') {
            /** @var TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> */
            return $target_php_settings;
        }
        $module_registry_address = $this->php_globals_finder->findModuleRegistry(
            $process_specifier,
            $target_php_settings,
        );
		$version_and_zts = null;
        if (!is_null($module_registry_address)) {
            $version_and_zts = $this->detectPhpVersion($process_specifier->pid, $module_registry_address);
        }

        if (is_null($version_and_zts)) {
            /** @var value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $version */
            $version = 'v' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
            Assert::true(ZendTypeReader::isSupported($version));
			$is_zts = false;
        } else {
			[$version, $is_zts] = $version_and_zts;
		}

        return new TargetPhpSettings(
            php_regex: $target_php_settings->php_regex,
            libpthread_regex: $target_php_settings->libpthread_regex,
            zts_globals_regex: $target_php_settings->zts_globals_regex,
            php_version: $version,
            php_path: $target_php_settings->php_path,
            libpthread_path: $target_php_settings->libpthread_path,
			zts: $is_zts,
        );
    }

    /** @return array{value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>, bool}|null */
    public function detectPhpVersion(
        int $pid,
        int $module_registry_address
    ): ?array {
        $fake_php_version = ZendTypeReader::V70;
        $dereferencer = $this->getDereferencer($pid, $fake_php_version);
        $module_registry = $this->getModuleRegistry(
            $module_registry_address,
            $fake_php_version,
            $dereferencer
        );
        $module_registry_entry_bucket = $module_registry->findByKey($dereferencer, 'standard');
        if (is_null($module_registry_entry_bucket)) {
            return null;
        }
        $module_registry_entry_pointer = $module_registry_entry_bucket->val;
        $module_entry_pointer = new Pointer(
            ZendModuleEntry::class,
            $module_registry_entry_pointer->value->lval,
            $this->getTypeReader($fake_php_version)->sizeOf('zend_module_entry')
        );
        /**
         * @var ZendModuleEntry $basic_module_entry
         * @psalm-ignore-var
         */
        $basic_module_entry = $dereferencer->deref($module_entry_pointer);
        $version = $basic_module_entry->getVersion($dereferencer);
        $result_string = 'v' . str_replace('.', '', $version);
        if (ZendTypeReader::isSupported($result_string)) {
            /** @var value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> */
            return [$result_string, $basic_module_entry->isZts()];
        }
        return null;
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $fake_version
     */
    private function getModuleRegistry(
        int $module_registry_address,
        string $fake_php_version,
        Dereferencer $dereferencer
    ): ZendArray {
        /** @var value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $fake_php_version */
        $pointer = new Pointer(
            ZendArray::class,
            $module_registry_address,
            $this->getTypeReader($fake_php_version)->sizeOf('zend_array')
        );
        return $dereferencer->deref($pointer);
    }
}
