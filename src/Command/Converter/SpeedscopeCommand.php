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

namespace Reli\Command\Converter;

use Reli\Converter\PhpSpyCompatibleParser;
use Reli\Converter\Speedscope\Settings\SpeedscopeConverterSettingsFromConsoleInput;
use Reli\Converter\Speedscope\SpeedscopeConverter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SpeedscopeCommand extends Command
{
    public function __construct(
        private SpeedscopeConverter $speedscope_converter,
        private PhpSpyCompatibleParser $parser,
        private SpeedscopeConverterSettingsFromConsoleInput $settings_from_console_input,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('converter:speedscope')
            ->setDescription('convert traces to the speedscope file format')
        ;
        $this->settings_from_console_input->setOptions($this);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->settings_from_console_input->createSettings($input);

        $output->write(
            \json_encode(
                $this->speedscope_converter->collectFrames(
                    $this->parser->parseFile(STDIN),
                    $settings,
                ),
                JSON_THROW_ON_ERROR | $settings->utf8_error_handling_type->toFlag(),
            ),
        );
        return 0;
    }
}
