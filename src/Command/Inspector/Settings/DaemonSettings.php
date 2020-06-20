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

final class DaemonSettings
{
    private int $threads;

    /**
     * DaemonSettings constructor.
     */
    public function __construct(int $threads)
    {
        $this->threads = $threads;
    }

    /**
     * @param InputInterface $input
     * @return self
     * @throws CommandSettingsException
     */
    public static function fromConsoleInput(InputInterface $input): self
    {
        $threads = $input->getOption('threads');
        if (is_null($threads)) {
            $threads = 8;
        }
        $threads = filter_var($threads, FILTER_VALIDATE_INT);
        if ($threads === false) {
            throw DaemonSettingsException::create(DaemonSettingsException::THREADS_IS_NOT_INTEGER);
        }
        return new self($threads);
    }
}
