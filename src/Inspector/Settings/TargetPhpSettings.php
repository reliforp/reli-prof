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

namespace PhpProfiler\Inspector\Settings;

use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use Symfony\Component\Console\Input\InputInterface;

final class TargetPhpSettings
{
    private const PHP_REGEX_DEFAULT = '.*/(php(74|7.4|80|8.0)?|php-fpm|libphp[78].*\.so)$';
    private const LIBPTHREAD_REGEX_DEFAULT = '.*/libpthread.*\.so$';
    private const TARGET_PHP_VERSION_DEFAULT = ZendTypeReader::V74;

    public string $php_regex;
    public string $libpthread_regex;
    /** @psalm-var value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */
    public string $php_version;
    public ?string $php_path;
    public ?string $libpthread_path;

    /**
     * GetTraceSettings constructor.
     * @param string $php_regex
     * @param string $libpthread_regex
     * @param string $php_version
     * @param string|null $php_path
     * @param string|null $libpthread_path
     * @psalm-param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    public function __construct(
        string $php_regex = self::PHP_REGEX_DEFAULT,
        string $libpthread_regex = self::LIBPTHREAD_REGEX_DEFAULT,
        string $php_version = ZendTypeReader::V74,
        ?string $php_path = null,
        ?string $libpthread_path = null
    ) {
        $this->php_regex = '{' . $php_regex . '}';
        $this->libpthread_regex = '{' . $libpthread_regex . '}';
        $this->php_version = $php_version;
        $this->php_path = $php_path;
        $this->libpthread_path = $libpthread_path;
    }

    public static function fromConsoleInput(InputInterface $input): self
    {
        $php_regex = $input->getOption('php-regex') ?? self::PHP_REGEX_DEFAULT;
        if (!is_string($php_regex)) {
            throw TargetPhpInspectorSettingsException::create(
                TargetPhpInspectorSettingsException::PHP_REGEX_IS_NOT_STRING
            );
        }

        $libpthread_regex = $input->getOption('libpthread-regex') ?? self::LIBPTHREAD_REGEX_DEFAULT;
        if (!is_string($libpthread_regex)) {
            throw TargetPhpInspectorSettingsException::create(
                TargetPhpInspectorSettingsException::LIBPTHREAD_REGEX_IS_NOT_STRING
            );
        }

        $php_version = $input->getOption('php-version') ?? self::TARGET_PHP_VERSION_DEFAULT;
        if (!in_array($php_version, ZendTypeReader::ALL_SUPPORTED_VERSIONS, true)) {
            throw TargetPhpInspectorSettingsException::create(
                TargetPhpInspectorSettingsException::TARGET_PHP_VERSION_INVALID
            );
        }
        /** @psalm-var value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */

        $php_path = $input->getOption('php-path');
        if (!is_null($php_path) and !is_string($php_path)) {
            throw TargetPhpInspectorSettingsException::create(
                TargetPhpInspectorSettingsException::PHP_PATH_IS_NOT_STRING
            );
        }

        $libpthread_path = $input->getOption('libpthread-path');
        if (!is_null($libpthread_path) and !is_string($libpthread_path)) {
            throw TargetPhpInspectorSettingsException::create(
                TargetPhpInspectorSettingsException::LIBPTHREAD_PATH_IS_NOT_STRING
            );
        }

        return new self($php_regex, $libpthread_regex, $php_version, $php_path, $libpthread_path);
    }
}
