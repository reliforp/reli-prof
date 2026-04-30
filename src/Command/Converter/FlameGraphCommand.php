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
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Reli\Converter\PhpSpyCompatibleFormatter;
use Reli\Converter\TraceInputReader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class FlameGraphCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    public function __construct(
        private Config $config,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('converter:flamegraph')
            ->setDescription('convert traces to flamegraph SVG (auto-detects rbt or phpspy input)')
            ->addMemoryLimitOption()
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyMemoryLimit($input, $output);
        $tools_path = Cast::toString($this->config->get('paths.tools'));

        $flamegraph = "{$tools_path}/flamegraph/flamegraph.pl";
        $stackcollapse = "{$tools_path}/stackcollapse-phpspy/stackcollapse-phpspy.pl";

        $stackcollapse_process = proc_open(
            [
                $stackcollapse,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => STDERR,
            ],
            $stackcollapse_pipes
        );
        if ($stackcollapse_process === false) {
            throw new \RuntimeException('Failed to open stackcollapse process');
        }
        $flamegraph_process = proc_open(
            [
                $flamegraph,
            ],
            [
                0 => $stackcollapse_pipes[1],
                1 => STDOUT,
                2 => STDERR,
            ],
            $flamegraph_pipes
        );
        if ($flamegraph_process === false) {
            fclose($stackcollapse_pipes[0]);
            fclose($stackcollapse_pipes[1]);
            proc_close($stackcollapse_process);
            throw new \RuntimeException('Failed to open flamegraph process');
        }

        // Close our copy of stackcollapse's stdout so flamegraph sees EOF
        // once stackcollapse exits (otherwise flamegraph hangs).
        fclose($stackcollapse_pipes[1]);

        $reader = new TraceInputReader();
        $formatter = new PhpSpyCompatibleFormatter();
        $formatter->writeTracesTo($stackcollapse_pipes[0], $reader->read(STDIN));
        fclose($stackcollapse_pipes[0]);

        $stackcollapse_status = proc_close($stackcollapse_process);
        $flamegraph_status = proc_close($flamegraph_process);

        if ($stackcollapse_status !== 0) {
            return $stackcollapse_status;
        }
        if ($flamegraph_status !== 0) {
            return $flamegraph_status;
        }
        return 0;
    }
}
