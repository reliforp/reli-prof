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

        $callers_re = self::wrapPattern($callers_pattern);
        $callees_re = self::wrapPattern($callees_pattern);
        $match_re = self::wrapPattern($match_pattern);
        $hide_re = self::wrapPattern($hide_pattern);

        $reader = new BinaryTraceReader();
        $stream = StreamDecompressor::decompressIfNeeded(STDIN);

        /** @var array<string, int> */
        $self_counts = [];
        /** @var array<string, int> */
        $total_counts = [];
        /** @var array<string, int> */
        $caller_counts = [];
        /** @var array<string, int> */
        $callee_counts = [];

        $sample_count = 0;
        $matched_samples = 0;

        /**
         * Ring buffer of the most recent samples for --last. We store the
         * already-formatted frame keys (with the same hide/no-line rules
         * applied as the aggregations) so the tail render is just printing.
         *
         * @var list<list<string>>
         */
        $tail_buffer = [];

        foreach ($reader->read($stream) as $sample) {
            $sample_count++;

            $keys = [];
            foreach ($sample->trace->call_frames as $frame) {
                $keys[] = $no_line
                    ? $frame->function_name
                    : $frame->function_name . ' ' . $frame->file_name . ':' . $frame->lineno;
            }

            if ($hide_re !== null) {
                $keys = array_values(
                    array_filter($keys, static fn(string $k): bool => preg_match($hide_re, $k) !== 1),
                );
            }
            if ($keys === []) {
                continue;
            }

            if ($match_re !== null) {
                $hit = false;
                foreach ($keys as $k) {
                    if (preg_match($match_re, $k) === 1) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
            }

            $matched_samples++;

            // self-time: leaf frame (call_frames[0] is innermost)
            $leaf = $keys[0];
            $self_counts[$leaf] = ($self_counts[$leaf] ?? 0) + 1;

            // inclusive: each frame on the stack, deduped per sample so
            // recursive calls only count once.
            $seen = [];
            foreach ($keys as $k) {
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $total_counts[$k] = ($total_counts[$k] ?? 0) + 1;
            }

            if ($callers_re !== null) {
                // Find topmost (closest to leaf) frame matching pattern,
                // attribute one count to its immediate caller.
                $n = count($keys);
                for ($i = 0; $i < $n; $i++) {
                    if (preg_match($callers_re, $keys[$i]) === 1) {
                        $caller = $keys[$i + 1] ?? '<root>';
                        $caller_counts[$caller] = ($caller_counts[$caller] ?? 0) + 1;
                        break;
                    }
                }
            }

            if ($callees_re !== null) {
                // Find bottommost (closest to root) matching frame, attribute
                // one count to its immediate callee.
                for ($i = count($keys) - 1; $i >= 0; $i--) {
                    if (preg_match($callees_re, $keys[$i]) === 1) {
                        $callee = $i > 0 ? $keys[$i - 1] : '<leaf>';
                        $callee_counts[$callee] = ($callee_counts[$callee] ?? 0) + 1;
                        break;
                    }
                }
            }

            if ($last_count > 0) {
                $tail_buffer[] = $keys;
                if (count($tail_buffer) > $last_count) {
                    array_shift($tail_buffer);
                }
            }
        }

        $err = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;

        $this->writeSummary($err, $reader, $sample_count, $matched_samples);

        if ($last_count > 0) {
            $this->printTail($output, $tail_buffer);
        }

        $denominator = $matched_samples > 0 ? $matched_samples : 1;

        if ($top > 0) {
            $this->printTable($output, 'self-time top', $self_counts, $denominator, $top);
            $this->printTable($output, 'total-time top (inclusive)', $total_counts, $denominator, $top);
        }

        // --top 0 only suppresses the default self/total tables. The
        // callers/callees tables are explicit asks; always render them
        // (with their own row cap so the user can still bound output).
        $explicit_top = $top > 0 ? $top : 20;

        if ($callers_re !== null) {
            $this->printTable(
                $output,
                "callers of frames matching /{$callers_pattern}/",
                $caller_counts,
                $denominator,
                $explicit_top,
            );
        }
        if ($callees_re !== null) {
            $this->printTable(
                $output,
                "callees of frames matching /{$callees_pattern}/",
                $callee_counts,
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

    /**
     * Wrap a user-supplied pattern in `#...#` delimiters and escape
     * any embedded `#`. Returns a non-empty regex string (the wrapper
     * always contributes at least 2 chars), or null when the input
     * was missing or empty so callers can skip the preg_match step.
     *
     * @return non-empty-string|null
     */
    private static function wrapPattern(?string $pattern): ?string
    {
        if ($pattern === null || $pattern === '') {
            return null;
        }
        return '#' . str_replace('#', '\\#', $pattern) . '#';
    }
}
