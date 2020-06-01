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

namespace PhpProfiler\Command\Inspector\Settings;

use PhpProfiler\Command\CommandSettingsException;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use Symfony\Component\Console\Input\InputInterface;

class TargetProcessSettings
{
    private const PHP_REGEX_DEFAULT = '.*/(php(74|7.4|80|8.0)?|php-fpm|libphp[78].*\.so)$';
    private const LIBPTHREAD_REGEX_DEFAULT = '.*/libpthread.*\.so$';
    private const TARGET_PHP_VERSION_DEFAULT = ZendTypeReader::V74;

    public int $pid;
    public string $php_regex;
    public string $libpthread_regex;
    /** @psalm-var value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */
    public string $php_version;
    public ?string $php_path;
    public ?string $libpthread_path;

    /**
     * GetTraceSettings constructor.
     * @param int $pid
     * @param string $php_regex
     * @param string $libpthread_regex
     * @param string $php_version
     * @param string|null $php_path
     * @param string|null $libpthread_path
     * @psalm-param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    public function __construct(
        int $pid,
        string $php_regex = self::PHP_REGEX_DEFAULT,
        string $libpthread_regex = self::LIBPTHREAD_REGEX_DEFAULT,
        string $php_version = ZendTypeReader::V74,
        ?string $php_path = null,
        ?string $libpthread_path = null
    ) {
        $this->pid = $pid;
        $this->php_regex = '{' . $php_regex . '}';
        $this->libpthread_regex = '{' . $libpthread_regex . '}';
        $this->php_version = $php_version;
        $this->php_path = $php_path;
        $this->libpthread_path = $libpthread_path;
    }

    /**
     * @param InputInterface $input
     * @return self
     * @throws CommandSettingsException
     */
    public static function fromConsoleInput(InputInterface $input): self
    {
        $pid = $input->getOption('pid');
        if (is_null($pid)) {
            throw TargetProcessSettingsException::create(
                TargetProcessSettingsException::PID_NOT_SPECIFIED
            );
        }
        $pid = filter_var($pid, FILTER_VALIDATE_INT);
        if ($pid === false) {
            throw TargetProcessSettingsException::create(
                TargetProcessSettingsException::PID_NOT_SPECIFIED
            );
        }

        $php_regex = $input->getOption('php-regex') ?? self::PHP_REGEX_DEFAULT;
        if (!is_string($php_regex)) {
            throw TargetProcessSettingsException::create(
                TargetProcessSettingsException::PHP_REGEX_IS_NOT_STRING
            );
        }

        $libpthread_regex = $input->getOption('libpthread-regex') ?? self::LIBPTHREAD_REGEX_DEFAULT;
        if (!is_string($libpthread_regex)) {
            throw TargetProcessSettingsException::create(
                TargetProcessSettingsException::LIBPTHREAD_REGEX_IS_NOT_STRING
            );
        }

        $php_version = $input->getOption('php-version') ?? self::TARGET_PHP_VERSION_DEFAULT;
        if (!in_array($php_version, ZendTypeReader::ALL_SUPPORTED_VERSIONS, true)) {
            throw TargetProcessSettingsException::create(
                TargetProcessSettingsException::TARGET_PHP_VERSION_INVALID
            );
        }
        /** @psalm-var value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */

        $php_path = $input->getOption('php-path');
        if (!is_null($php_path) and !is_string($php_path)) {
            throw TargetProcessSettingsException::create(
                TargetProcessSettingsException::PHP_PATH_IS_NOT_STRING
            );
        }

        $libpthread_path = $input->getOption('libpthread-path');
        if (!is_null($libpthread_path) and !is_string($libpthread_path)) {
            throw TargetProcessSettingsException::create(
                TargetProcessSettingsException::LIBPTHREAD_PATH_IS_NOT_STRING
            );
        }

        return new self($pid, $php_regex, $libpthread_regex, $php_version, $php_path, $libpthread_path);
    }
}
