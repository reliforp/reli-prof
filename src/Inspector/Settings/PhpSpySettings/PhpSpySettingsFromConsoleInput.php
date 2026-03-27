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

namespace Reli\Inspector\Settings\PhpSpySettings;

use PhpCast\NullableCast;
use Reli\Inspector\Settings\InspectorSettingsException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function filter_var;
use function is_null;

use const FILTER_VALIDATE_INT;

final class PhpSpySettingsFromConsoleInput
{
    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'phpspy-path',
                null,
                InputOption::VALUE_REQUIRED,
                'path to the phpspy binary'
            )
            ->addOption(
                'sleep-ns',
                's',
                InputOption::VALUE_OPTIONAL,
                'phpspy sleep between samples in nanoseconds (default: 10101010)'
            )
            ->addOption(
                'buffer-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'phpspy buffer size (default: 4096)'
            )
            ->addOption(
                'rate-hz',
                'H',
                InputOption::VALUE_OPTIONAL,
                'phpspy sampling rate in Hz (default: 99)'
            )
            ->addOption(
                'phpspy-args',
                null,
                InputOption::VALUE_REQUIRED,
                'extra arguments to pass to phpspy'
            )
        ;
    }

    /**
     * @throws InspectorSettingsException
     */
    public function createSettings(InputInterface $input): PhpSpySettings
    {
        $phpspy_path = NullableCast::toString($input->getOption('phpspy-path'));

        $sleep_ns = NullableCast::toString($input->getOption('sleep-ns'));
        if (is_null($sleep_ns)) {
            $sleep_ns = PhpSpySettings::DEFAULT_SLEEP_NS;
        } else {
            $sleep_ns = filter_var($sleep_ns, FILTER_VALIDATE_INT);
            if ($sleep_ns === false) {
                throw PhpSpySettingsException::create(PhpSpySettingsException::SLEEP_NS_IS_NOT_INTEGER);
            }
        }

        $buffer_size = NullableCast::toString($input->getOption('buffer-size'));
        if (is_null($buffer_size)) {
            $buffer_size = PhpSpySettings::DEFAULT_BUFFER_SIZE;
        } else {
            $buffer_size = filter_var($buffer_size, FILTER_VALIDATE_INT);
            if ($buffer_size === false) {
                throw PhpSpySettingsException::create(PhpSpySettingsException::BUFFER_SIZE_IS_NOT_INTEGER);
            }
        }

        $rate_hz = NullableCast::toString($input->getOption('rate-hz'));
        if (is_null($rate_hz)) {
            $rate_hz = PhpSpySettings::DEFAULT_RATE_HZ;
        } else {
            $rate_hz = filter_var($rate_hz, FILTER_VALIDATE_INT);
            if ($rate_hz === false) {
                throw PhpSpySettingsException::create(PhpSpySettingsException::RATE_HZ_IS_NOT_INTEGER);
            }
        }

        $extra_args = NullableCast::toString($input->getOption('phpspy-args'));

        return new PhpSpySettings(
            $phpspy_path,
            $sleep_ns,
            $buffer_size,
            $rate_hz,
            $extra_args,
        );
    }
}
