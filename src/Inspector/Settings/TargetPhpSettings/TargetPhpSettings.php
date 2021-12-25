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

namespace PhpProfiler\Inspector\Settings\TargetPhpSettings;

use PhpProfiler\Lib\PhpInternals\ZendTypeReader;

/** @psalm-immutable */
final class TargetPhpSettings
{
    public const PHP_REGEX_DEFAULT = '.*/(php(74|7.4|80|8.0)?|php-fpm|libphp[78]?.*\.so)$';
    public const LIBPTHREAD_REGEX_DEFAULT = '.*/libpthread.*\.so';
    public const TARGET_PHP_VERSION_DEFAULT = ZendTypeReader::V80;

    /** @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */
    public function __construct(
        private string $php_regex = self::PHP_REGEX_DEFAULT,
        private string $libpthread_regex = self::LIBPTHREAD_REGEX_DEFAULT,
        public string $php_version = self::TARGET_PHP_VERSION_DEFAULT,
        public ?string $php_path = null,
        public ?string $libpthread_path = null
    ) {
    }

    public function getDelimitedPhpRegex(): string
    {
        return $this->getDelimitedRegex($this->php_regex);
    }

    public function getDelimitedLibPthreadRegex(): string
    {
        return $this->getDelimitedRegex($this->libpthread_regex);
    }

    private function getDelimitedRegex(string $regex): string
    {
        return '{' . $regex . '}';
    }

    /** @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */
    public function alterPhpVersion(string $php_version): self
    {
        return new self(
            php_regex: $this->php_regex,
            libpthread_regex: $this->libpthread_regex,
            php_version: $php_version,
            php_path: $this->php_path,
            libpthread_path: $this->libpthread_path,
        );
    }
}
