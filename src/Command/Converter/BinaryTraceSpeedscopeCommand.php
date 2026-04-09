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

use Reli\Converter\BinaryTrace\BinaryTraceReader;
use Reli\Converter\Speedscope\Settings\SpeedscopeConverterSettingsFromConsoleInput;
use Reli\Converter\Speedscope\SpeedscopeConverter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class BinaryTraceSpeedscopeCommand extends Command
{
    public function __construct(
        private SpeedscopeConverter $speedscope_converter,
        private SpeedscopeConverterSettingsFromConsoleInput $settings_from_console_input,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('converter:binary-trace-to-speedscope')
            ->setDescription('convert binary trace format to speedscope JSON')
        ;
        $this->settings_from_console_input->setOptions($this);
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->settings_from_console_input->createSettings($input);
        $reader = new BinaryTraceReader();

        // SpeedscopeConverter expects iterable<ParsedCallTrace>
        $traces = (function () use ($reader) {
            foreach ($reader->read(STDIN) as $sample) {
                yield $sample->trace;
            }
        })();

        $encoded = \json_encode(
            $this->speedscope_converter->collectFrames($traces, $settings),
            JSON_THROW_ON_ERROR | $settings->utf8_error_handling_type->toFlag(),
        );
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode JSON');
        }
        $output->write($encoded);

        return 0;
    }
}
