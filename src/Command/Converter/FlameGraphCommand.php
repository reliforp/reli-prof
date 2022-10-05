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

use Noodlehaus\Config;
use PhpCast\Cast;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class FlameGraphCommand extends Command
{
    public function __construct(
        private Config $config,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('converter:flamegraph')
            ->setDescription('convert phpspy-compatible trace file to flamegraph')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $tools_path = Cast::toString($this->config->get('paths.tools'));

        $flamegraph = "{$tools_path}/flamegraph/flamegraph.pl";
        $stackcollapse = "{$tools_path}/stackcollapse-phpspy/stackcollapse-phpspy.pl";

        $stackcollapse_process = proc_open(
            [
                $stackcollapse,
            ],
            [
                0 => STDIN,
                1 => ['pipe', 'w'],
                2 => STDERR,
            ],
            $stackcollapse_pipes
        );
        $flamegraph_process = proc_open(
            [
                $flamegraph,
            ],
            [
                0 => $stackcollapse_pipes[1],
                1 => STDOUT,
                2 => STDERR,
            ],
            $stackcollapse_pipes
        );

        proc_close($stackcollapse_process);
        proc_close($flamegraph_process);

        return 0;
    }
}
