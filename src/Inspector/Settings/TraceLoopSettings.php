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

use Symfony\Component\Console\Input\InputInterface;

final class TraceLoopSettings
{
    private const SLEEP_NANO_SECONDS_DEFAULT = 1000 * 1000 * 10;
    private const CANCEL_KEY_DEFAULT = 'q';
    private const MAX_RETRY_DEFAULT = -1;

    public int $sleep_nano_seconds;
    public string $cancel_key;
    public int $max_retries;

    /**
     * TraceLoopSettings constructor.
     * @param int $sleep_nano_seconds
     * @param string $cancel_key
     * @param int $max_retries
     */
    public function __construct(int $sleep_nano_seconds, string $cancel_key, int $max_retries)
    {
        $this->sleep_nano_seconds = $sleep_nano_seconds;
        $this->cancel_key = $cancel_key;
        $this->max_retries = $max_retries;
    }

    /**
     * @param InputInterface $input
     * @return self
     * @throws InspectorSettingsException
     */
    public static function fromConsoleInput(InputInterface $input): self
    {
        $sleep_nano_seconds = $input->getOption('sleep-ns');
        if (is_null($sleep_nano_seconds)) {
            $sleep_nano_seconds = self::SLEEP_NANO_SECONDS_DEFAULT;
        }
        $sleep_nano_seconds = filter_var($sleep_nano_seconds, FILTER_VALIDATE_INT);
        if ($sleep_nano_seconds === false) {
            throw TraceLoopInspectorSettingsException::create(
                TraceLoopInspectorSettingsException::SLEEP_NS_IS_NOT_INTEGER
            );
        }

        $max_retries = $input->getOption('max-retries');
        if (is_null($max_retries)) {
            $max_retries = self::MAX_RETRY_DEFAULT;
        }
        $max_retries = filter_var($max_retries, FILTER_VALIDATE_INT);
        if ($max_retries === false) {
            throw TraceLoopInspectorSettingsException::create(
                TraceLoopInspectorSettingsException::MAX_RETRY_IS_NOT_INTEGER
            );
        }

        return new self($sleep_nano_seconds, self::CANCEL_KEY_DEFAULT, $max_retries);
    }
}
