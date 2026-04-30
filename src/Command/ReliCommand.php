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

namespace Reli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ReliCommand extends Command
{
    /**
     * Env var that {@see worker-entry.php} reads to raise the
     * `memory_limit` of every amphp-spawned worker (e.g. the readers
     * fanned out by `inspector:daemon` / `inspector:top`). `ini_set` in
     * the parent process does not propagate; this env-var bridge does.
     */
    public const string MEMORY_LIMIT_ENV = 'RELI_MEMORY_LIMIT';

    abstract public static function getDockerProfile(): DockerProfile;

    /**
     * Returns `static` so the helper composes inside Command::configure()
     * chains (`$this->setName(...)->setDescription(...)->addMemoryLimitOption()`).
     */
    protected function addMemoryLimitOption(): static
    {
        return $this->addOption(
            'memory-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'set PHP memory_limit for this reli process (e.g. 2G, 512M).'
            . ' Daemon-style commands (inspector:daemon, inspector:top)'
            . ' propagate the value to their spawned worker processes via'
            . ' the ' . self::MEMORY_LIMIT_ENV . ' env var.',
        );
    }

    protected function applyMemoryLimit(InputInterface $input, OutputInterface $output): void
    {
        /** @var mixed $raw */
        $raw = $input->getOption('memory-limit');
        if (!is_string($raw) || $raw === '') {
            return;
        }
        if (ini_set('memory_limit', $raw) === false) {
            $err = $output instanceof ConsoleOutputInterface
                ? $output->getErrorOutput()
                : $output;
            $err->writeln(
                "<comment>warning: ini_set('memory_limit', '{$raw}') failed;"
                . ' value may be malformed (expected e.g. 2G, 512M, -1).</comment>',
            );
            return;
        }
        // Propagate to amphp workers spawned via Lib\Amphp\ContextCreator.
        // putenv() updates the current process env, which child processes
        // started via proc_open / Amp\Process inherit by default.
        putenv(self::MEMORY_LIMIT_ENV . '=' . $raw);
    }
}
