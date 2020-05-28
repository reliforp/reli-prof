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
use Symfony\Component\Console\Input\InputInterface;

class TargetProcessSettings
{
    private const PHP_REGEX_DEFAULT = '.*\/(php(74|7.4|80|8.0)?|php-fpm|libphp[78].*\.so)$';
    private const LIBPTHREAD_REGEX_DEFAULT = '.*\/libpthread.*\.so$';

    public int $pid;
    public string $php_regex;
    public string $libpthread_regex;

    /**
     * GetTraceSettings constructor.
     * @param int $pid
     * @param string $php_regex
     * @param string $libpthread_regex
     */
    public function __construct(
        int $pid,
        string $php_regex = self::PHP_REGEX_DEFAULT,
        string $libpthread_regex = self::LIBPTHREAD_REGEX_DEFAULT
    ) {
        $this->pid = $pid;
        $this->php_regex = '/' . $php_regex . '/';
        $this->libpthread_regex = '/' . $libpthread_regex . '/';
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

        return new self($pid, $php_regex, $libpthread_regex);
    }
}
