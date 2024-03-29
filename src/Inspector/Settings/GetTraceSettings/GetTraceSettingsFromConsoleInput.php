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

namespace Reli\Inspector\Settings\GetTraceSettings;

use PhpCast\NullableCast;
use Reli\Inspector\Settings\InspectorSettingsException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function filter_var;
use function is_null;

use const FILTER_VALIDATE_INT;

final class GetTraceSettingsFromConsoleInput
{
    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'depth',
                'd',
                InputOption::VALUE_OPTIONAL,
                'max depth'
            )
        ;
    }

    /**
     * @throws InspectorSettingsException
     */
    public function createSettings(InputInterface $input): GetTraceSettings
    {
        $depth = NullableCast::toString($input->getOption('depth'));
        if (is_null($depth)) {
            $depth = PHP_INT_MAX;
        }
        $depth = filter_var($depth, FILTER_VALIDATE_INT);
        if ($depth === false) {
            throw GetTraceSettingsException::create(GetTraceSettingsException::DEPTH_IS_NOT_INTEGER);
        }
        return new GetTraceSettings($depth);
    }
}
