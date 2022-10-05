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

namespace Reli\Inspector\Settings\OutputSettings;

use Noodlehaus\Config;
use PhpCast\NullableCast;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class OutputSettingsFromConsoleInput
{
    public function __construct(
        private Config $config
    ) {
    }

    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'template',
                't',
                InputOption::VALUE_OPTIONAL,
                'template name (phpspy|phpspy_with_opcode|json_lines) (default: phpspy)'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'path to write output from this tool (default: stdout)'
            )
        ;
    }

    public function createSettings(InputInterface $input): OutputSettings
    {
        $template = NullableCast::toString($input->getOption('template'));
        if (is_null($template)) {
            $template = NullableCast::toString($this->config->get('output.template.default'));
            if (is_null($template)) {
                throw OutputSettingsException::create(
                    OutputSettingsException::TEMPLATE_NOT_SPECIFIED
                );
            }
        }

        $output_path = $input->getOption('output');
        if (!is_null($output_path) and !is_string($output_path)) {
            throw OutputSettingsException::create(
                OutputSettingsException::OUTPUT_IS_NOT_STRING
            );
        }

        return new OutputSettings(
            $template,
            $output_path,
        );
    }
}
