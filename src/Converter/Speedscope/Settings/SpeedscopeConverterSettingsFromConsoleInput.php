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

namespace Reli\Converter\Speedscope\Settings;

use PhpCast\Cast;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class SpeedscopeConverterSettingsFromConsoleInput
{
    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'utf8-errors',
                null,
                InputOption::VALUE_REQUIRED,
                'utf8 error handling type (ignore|substitute|fail)',
                'ignore'
            );
        ;
    }

    public function createSettings(InputInterface $input): SpeedscopeConverterSettings
    {
        return new SpeedscopeConverterSettings(
            $this->utf8ErrorHandlingTypeFromString(
                Cast::toString($input->getOption('utf8-errors'))
            ),
        );
    }

    private function utf8ErrorHandlingTypeFromString(string $input): Utf8ErrorHandlingType
    {
        return match ($input) {
            'substitute' => Utf8ErrorHandlingType::Substitute,
            'ignore' => Utf8ErrorHandlingType::Ignore,
            'fail' => Utf8ErrorHandlingType::Fail,
            default => throw SpeedscopeConverterSettingsException::create(
                SpeedscopeConverterSettingsException::UNSUPPORTED_UTF8_ERROR_HANDLING
            ),
        };
    }
}
