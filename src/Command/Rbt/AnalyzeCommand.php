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

namespace Reli\Command\Rbt;

use Reli\Converter\BinaryTrace\BinaryTraceReader;
use Reli\Converter\StreamDecompressor;
use Reli\Rbt\Analyze\TraceAggregationResult;
use Reli\Rbt\Analyze\TraceAggregator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Aggregates a binary trace (.rbt) into hot-frame and call-site reports.
 *
 * Reads from STDIN (gzip-aware) and produces text tables of self-time and
 * inclusive (total) time, plus optional caller/callee views for finding the
 * call sites that dominate the profile of a given function.
 */
final class AnalyzeCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('rbt:analyze')
            ->setDescription('Aggregate a binary trace (.rbt) into hot-frame and call-site reports')
            ->addOption(
                'top',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of rows to show per table (0 to suppress aggregation tables)',
                '20',
            )
            ->addOption(
                'last',
                null,
                InputOption::VALUE_OPTIONAL,
                'Print the last N samples\' call stacks (default 1) — useful for'
                . ' watching an in-progress trace and seeing the current execution position',
                false,
            )
            ->addOption(
                'callers',
                null,
                InputOption::VALUE_REQUIRED,
                'Show callers of frames matching this PCRE pattern (without delimiters)',
            )
            ->addOption(
                'callees',
                null,
                InputOption::VALUE_REQUIRED,
                'Show callees of frames matching this PCRE pattern (without delimiters)',
            )
            ->addOption(
                'match',
                null,
                InputOption::VALUE_REQUIRED,
                'Only count samples whose stack contains a frame matching this PCRE pattern',
            )
            ->addOption(
                'hide',
                null,
                InputOption::VALUE_REQUIRED,
                'Drop frames matching this PCRE pattern from each stack before counting',
            )
            ->addOption(
                'no-line',
                null,
                InputOption::VALUE_NONE,
                'Group frames by function name only (ignore file:line)',
            )
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $top = max(0, (int) $input->getOption('top'));
        /** @var string|null $callers_pattern */
        $callers_pattern = $input->getOption('callers');
        /** @var string|null $callees_pattern */
        $callees_pattern = $input->getOption('callees');
        /** @var string|null $match_pattern */
        $match_pattern = $input->getOption('match');
        /** @var string|null $hide_pattern */
        $hide_pattern = $input->getOption('hide');
        $no_line = (bool) $input->getOption('no-line');

        // --last:
        //   absent  → false (no tail)
        //   present → null (default count)
        //   =N      → "N"  (explicit count)
        /** @var string|false|null $last_raw */
        $last_raw = $input->getOption('last');
        $last_count = 0;
        if ($last_raw !== false) {
            $last_count = $last_raw === null ? 1 : max(1, (int) $last_raw);
        }

        $aggregator = new TraceAggregator(
            no_line: $no_line,
            hide_re: TraceAggregator::wrapPattern($hide_pattern),
            match_re: TraceAggregator::wrapPattern($match_pattern),
            callers_re: TraceAggregator::wrapPattern($callers_pattern),
            callees_re: TraceAggregator::wrapPattern($callees_pattern),
            last_count: $last_count,
        );

        $reader = new BinaryTraceReader();
        $stream = StreamDecompressor::decompressIfNeeded(STDIN);
        $result = $aggregator->aggregate($reader->read($stream));

        $err = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;

        $this->writeSummary($err, $reader, $result->sample_count, $result->matched_samples);

        if ($last_count > 0) {
            $this->printTail($output, $result->tail);
        }

        $denominator = $result->matched_samples > 0 ? $result->matched_samples : 1;

        if ($top > 0) {
            $this->printTable($output, 'self-time top', $result->self_counts, $denominator, $top);
            $this->printTable($output, 'total-time top (inclusive)', $result->total_counts, $denominator, $top);
        }

        // --top 0 only suppresses the default self/total tables. The
        // callers/callees tables are explicit asks; always render them
        // (with their own row cap so the user can still bound output).
        $explicit_top = $top > 0 ? $top : 20;

        if ($aggregator->callers_re !== null) {
            $this->printTable(
                $output,
                "callers of frames matching /{$callers_pattern}/",
                $result->caller_counts,
                $denominator,
                $explicit_top,
            );
        }
        if ($aggregator->callees_re !== null) {
            $this->printTable(
                $output,
                "callees of frames matching /{$callees_pattern}/",
                $result->callee_counts,
                $denominator,
                $explicit_top,
            );
        }

        return 0;
    }

    /**
     * Render the most recent samples' call stacks. Each frame is printed on
     * its own line in phpspy depth order — depth 0 (the leaf, i.e. the
     * actual current execution position) at the top of each stack — so the
     * line a glance lands on is the one that matters for "where am I now?".
     *
     * @param list<list<string>> $tail
     */
    private function printTail(OutputInterface $output, array $tail): void
    {
        $output->writeln('');
        $output->writeln('<comment># last ' . count($tail) . ' sample(s)</comment>');
        if ($tail === []) {
            $output->writeln('  (no samples)');
            return;
        }
        $count = count($tail);
        foreach ($tail as $i => $stack) {
            // tail is in chronological order (oldest first, newest last) so
            // the line a glance lands on at the bottom of the output is
            // always the most recent sample. Label each header by how many
            // samples ago it was, using "(now)" for the latest entry.
            $offset = $count - $i - 1;
            $label = $count === 1
                ? '(now)'
                : ($offset === 0 ? '(now)' : sprintf('(%d ago)', $offset));
            $output->writeln("  ── sample {$label} ──");
            foreach ($stack as $depth => $frame) {
                $output->writeln(sprintf('  [%2d] %s', $depth, $frame));
            }
        }
    }

    private function writeSummary(
        OutputInterface $output,
        BinaryTraceReader $reader,
        int $sample_count,
        int $matched_samples,
    ): void {
        $period = $reader->getSamplingPeriodUs();
        $duration_s = $period > 0 ? ($sample_count * $period) / 1_000_000 : 0.0;
        $output->writeln(sprintf(
            '<info>trace:</info> %d samples, %d matched, sampling period %d us, ~%.2f s sampled wall',
            $sample_count,
            $matched_samples,
            $period,
            $duration_s,
        ));
    }

    /**
     * @param array<string, int> $counts
     */
    private function printTable(
        OutputInterface $output,
        string $title,
        array $counts,
        int $denominator,
        int $top,
    ): void {
        arsort($counts);
        $rows = array_slice($counts, 0, $top, preserve_keys: true);

        $output->writeln('');
        $output->writeln("<comment># {$title}</comment>");

        if ($rows === []) {
            $output->writeln('  (no matches)');
            return;
        }

        $denom = (float)max(1, $denominator);
        $table = new Table($output);
        $table->setHeaders(['count', 'pct', 'frame']);
        foreach ($rows as $key => $count) {
            $pct = (float)$count * 100.0 / $denom;
            $table->addRow([
                (string) $count,
                sprintf('%5.1f%%', $pct),
                $key,
            ]);
        }
        $table->render();
    }
}
