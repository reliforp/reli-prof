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

use Reli\Converter\BinaryTrace\PprofEncoder;
use Reli\Converter\TraceInputReader;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PprofCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('converter:pprof')
            ->setDescription('convert traces to pprof protobuf (auto-detects rbt or phpspy input)')
            ->addMemoryLimitOption()
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyMemoryLimit($input, $output);
        $reader = new TraceInputReader();
        $encoder = new PprofEncoder();

        $pprof_data = $encoder->encode(
            $reader->read(STDIN),
            function () use ($reader): int {
                $period = $reader->getBinaryReader()?->getSamplingPeriodUs();
                return ($period !== null && $period > 0) ? $period : 10000;
            },
        );

        $compressed = gzencode($pprof_data);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to gzip-compress pprof data');
        }

        fwrite(STDOUT, $compressed);

        return 0;
    }
}
