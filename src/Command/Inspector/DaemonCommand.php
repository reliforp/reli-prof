<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Command\Inspector;

use Amp\Loop;
use Amp\Promise;
use Amp\Parallel\Context;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DaemonCommand extends Command
{
    public function configure(): void
    {
        $this->setName('inspector:daemon')
            ->setDescription('periodically get running function name from an outer process or thread');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = Context\create(__DIR__ . '/Worker/php-searcher.php');
        /** @var int $searcher_pid */
        $searcher_pid = Promise\wait($context->start());
        /** @var int[] $pid_list */
        $pid_list = Promise\wait($context->receive());
        $readers = [];
        foreach ($pid_list as $pid) {
            $context = Context\create(__DIR__ . '/Worker/php-reader.php');
            Promise\wait($context->start());
            Promise\wait($context->send($pid));
            $readers[$pid] = $context;
        }
        Loop::run(function () use (&$readers, $output) {
            Loop::repeat(10, function () use (&$readers, $output) {
                if (empty($readers)) {
                    return false;
                }
                $promises = [];
                foreach ($readers as $pid => $reader) {
                    if (!$reader->isRunning()) {
                        unset($readers[$pid]);
                        continue;
                    }
                    $promises[] = \Amp\call(
                        function () use ($reader, &$readers, $pid, $output) {
                            /** @var string */
                            unset($readers[$pid]);
                            $result = yield $reader->receive();
                            $output->write($result);
                            $readers[$pid] = $reader;
                        }
                    );
                }
                yield $promises;
            });
        });

        return 0;
    }
}
