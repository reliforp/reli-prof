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
                'output-format',
                'F',
                InputOption::VALUE_OPTIONAL,
                'output format (template:phpspy|template:phpspy_with_opcode|template:json_lines|rbt|rbt-bundled)'
            )
            ->addOption(
                'template',
                't',
                InputOption::VALUE_OPTIONAL,
                '[deprecated: use --output-format=template:{name}] template name'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'path to write output from this tool (default: stdout)'
            )
            ->addOption(
                'rbt-timestamps',
                null,
                InputOption::VALUE_REQUIRED,
                'timestamp mode for rbt format: none (compact, default) or delta (with timestamps)',
                'none',
            )
            ->addOption(
                'rbt-compress',
                null,
                InputOption::VALUE_NONE,
                'Gzip-compress completed rbt segments (daemon per-worker mode)',
            )
        ;
    }

    public function createSettings(InputInterface $input): OutputSettings
    {
        $output_format = NullableCast::toString($input->getOption('output-format'));

        // --template is a backward-compatible alias for --output-format=template:{name}
        if ($output_format === null) {
            $template = NullableCast::toString($input->getOption('template'));
            if ($template !== null) {
                $output_format = 'template:' . $template;
            }
        }

        if ($output_format === null) {
            $default_template = NullableCast::toString($this->config->get('output.template.default'));
            if ($default_template !== null) {
                $output_format = 'template:' . $default_template;
            } else {
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

        /** @var string $rbt_timestamps */
        $rbt_timestamps = $input->getOption('rbt-timestamps');

        $rbt_compress = (bool)$input->getOption('rbt-compress');

        return new OutputSettings(
            $output_format,
            $output_path,
            $rbt_timestamps,
            $rbt_compress,
        );
    }
}
