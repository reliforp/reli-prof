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
use Reli\Rbt\Analyze\FrameFormatter;
use Reli\Rbt\Analyze\SectionLayout;
use Reli\Rbt\Analyze\TraceAggregationResult;
use Reli\Rbt\Analyze\TraceAggregator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

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
                'Number of rows to show per table (0 to suppress the default self/total tables)',
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
                'last-depth',
                null,
                InputOption::VALUE_REQUIRED,
                'Cap each --last stack at N leaf-most frames (0 = no cap) so deeper stacks'
                . ' don\'t shove the rest of the report around under `watch`',
                '0',
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
            ->addOption(
                'with-opcode',
                null,
                InputOption::VALUE_NONE,
                'Include the VM opcode in each frame key (e.g. [ZEND_RECV])',
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path display mode: full (default) or short (basename only'
                . ' — display-only, does not affect aggregation)',
                FrameFormatter::PATH_FULL,
            )
            ->addOption(
                'crop',
                null,
                InputOption::VALUE_REQUIRED,
                'Crop each output line at N characters (or "auto" for terminal width) to prevent wrap;'
                . ' in multi-column layouts the width is split per column',
                '0',
            )
            ->addOption(
                'crop-anchor',
                null,
                InputOption::VALUE_REQUIRED,
                'When --crop clips a frame, anchor at "left" (keep the head, ellipsis on the right — default)'
                . ' or "right" (keep the leaf, ellipsis on the left). Tables keep their borders intact either way.',
                FrameFormatter::ANCHOR_LEFT,
            )
            ->addOption(
                'sections',
                null,
                InputOption::VALUE_REQUIRED,
                'Layout spec: "," stacks rows, "+" lays sections out side by side.'
                . ' Known sections: self, total, callers, callees, tail.'
                . ' Example: "self+total,tail"',
                SectionLayout::DEFAULT_SPEC,
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
        $with_opcode = (bool) $input->getOption('with-opcode');
        $last_depth = max(0, (int) $input->getOption('last-depth'));

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

        /** @var string $path_mode */
        $path_mode = $input->getOption('path');
        if (!in_array($path_mode, [FrameFormatter::PATH_FULL, FrameFormatter::PATH_SHORT], true)) {
            $output->writeln("<error>--path must be 'full' or 'short', got: {$path_mode}</error>");
            return 1;
        }

        /** @var string $crop_anchor */
        $crop_anchor = $input->getOption('crop-anchor');
        if (!in_array($crop_anchor, [FrameFormatter::ANCHOR_LEFT, FrameFormatter::ANCHOR_RIGHT], true)) {
            $output->writeln("<error>--crop-anchor must be 'left' or 'right', got: {$crop_anchor}</error>");
            return 1;
        }

        /** @var string $sections_spec */
        $sections_spec = $input->getOption('sections');
        try {
            $layout = SectionLayout::parse($sections_spec);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        $terminal_width = (new Terminal())->getWidth();
        $row_crop_widths = $this->resolveCrop(
            (string) $input->getOption('crop'),
            $layout,
            $terminal_width,
            $output,
        );
        if ($row_crop_widths === null) {
            return 1;
        }

        $aggregator = new TraceAggregator(
            no_line: $no_line,
            with_opcode: $with_opcode,
            hide_re: TraceAggregator::wrapPattern($hide_pattern),
            match_re: TraceAggregator::wrapPattern($match_pattern),
            callers_re: TraceAggregator::wrapPattern($callers_pattern),
            callees_re: TraceAggregator::wrapPattern($callees_pattern),
            last_count: $last_count,
            last_depth: $last_depth,
        );

        $reader = new BinaryTraceReader();
        $stream = StreamDecompressor::decompressIfNeeded(STDIN);
        $result = $aggregator->aggregate($reader->read($stream));

        $err = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;

        $this->writeSummary($err, $reader, $result->sample_count, $result->matched_samples);

        $sections = $this->renderSections(
            $result,
            new FrameFormatter($path_mode, $crop_anchor),
            $layout,
            $row_crop_widths,
            $top,
            $last_count,
            $callers_pattern,
            $callees_pattern,
        );

        $lines = $layout->assemble($sections);
        foreach ($lines as $line) {
            $output->writeln($line);
        }

        return 0;
    }

    /**
     * Resolve --crop into per-row per-column widths.
     *
     * For `auto`, each row's width budget is the terminal width minus the
     * gaps between its columns, divided evenly across the columns. A row
     * with one column gets the full terminal width; a 3-column row with
     * an 180-char terminal gets ~58 chars per column.
     *
     * Returns null on parse error after writing to the output.
     *
     * @return array<int, list<int>>|null
     */
    private function resolveCrop(
        string $raw,
        SectionLayout $layout,
        int $terminal_width,
        OutputInterface $output,
    ): ?array {
        $raw = trim($raw);
        $is_auto = strcasecmp($raw, 'auto') === 0;
        $fixed = 0;
        if (!$is_auto) {
            if (!preg_match('/^-?\d+$/', $raw)) {
                $output->writeln("<error>--crop must be 'auto' or an integer, got: {$raw}</error>");
                return null;
            }
            $fixed = max(0, (int) $raw);
        }

        $gap_width = strlen(SectionLayout::COLUMN_GAP);
        /** @var array<int, list<int>> $per_row */
        $per_row = [];
        foreach ($layout->rows as $row_index => $row) {
            $n = count($row);
            if (!$is_auto) {
                $per_row[$row_index] = array_fill(0, $n, $fixed);
                continue;
            }
            if ($terminal_width <= 0 || $n === 0) {
                $per_row[$row_index] = array_fill(0, $n, 0);
                continue;
            }
            $usable = $terminal_width - ($n - 1) * $gap_width;
            if ($usable <= 0) {
                $per_row[$row_index] = array_fill(0, $n, 0);
                continue;
            }
            $per_row[$row_index] = array_fill(0, $n, intdiv($usable, $n));
        }

        return $per_row;
    }

    /**
     * Render every known section to a list of plain-text lines, keyed by
     * section name so {@see SectionLayout::assemble()} can pick them up
     * in the requested order. Sections that wouldn't have content this
     * run (callers/callees without a pattern, tail without --last) are
     * omitted rather than rendered empty.
     *
     * @param array<int, list<int>> $row_crop_widths
     * @return array<string, list<string>>
     */
    private function renderSections(
        TraceAggregationResult $result,
        FrameFormatter $formatter,
        SectionLayout $layout,
        array $row_crop_widths,
        int $top,
        int $last_count,
        ?string $callers_pattern,
        ?string $callees_pattern,
    ): array {
        $denominator = $result->matched_samples > 0 ? $result->matched_samples : 1;
        $explicit_top = $top > 0 ? $top : 20;

        $crop_for_section = $this->buildSectionCropMap($layout, $row_crop_widths);

        /** @var array<string, list<string>> $sections */
        $sections = [];

        if ($top > 0) {
            $sections[SectionLayout::SECTION_SELF] = $this->renderTable(
                'self-time top',
                $result->self_counts,
                $denominator,
                $top,
                $formatter,
                $crop_for_section[SectionLayout::SECTION_SELF] ?? 0,
            );
            $sections[SectionLayout::SECTION_TOTAL] = $this->renderTable(
                'total-time top (inclusive)',
                $result->total_counts,
                $denominator,
                $top,
                $formatter,
                $crop_for_section[SectionLayout::SECTION_TOTAL] ?? 0,
            );
        }

        if ($callers_pattern !== null && $callers_pattern !== '') {
            $sections[SectionLayout::SECTION_CALLERS] = $this->renderTable(
                "callers of frames matching /{$callers_pattern}/",
                $result->caller_counts,
                $denominator,
                $explicit_top,
                $formatter,
                $crop_for_section[SectionLayout::SECTION_CALLERS] ?? 0,
            );
        }

        if ($callees_pattern !== null && $callees_pattern !== '') {
            $sections[SectionLayout::SECTION_CALLEES] = $this->renderTable(
                "callees of frames matching /{$callees_pattern}/",
                $result->callee_counts,
                $denominator,
                $explicit_top,
                $formatter,
                $crop_for_section[SectionLayout::SECTION_CALLEES] ?? 0,
            );
        }

        if ($last_count > 0) {
            $sections[SectionLayout::SECTION_TAIL] = $this->renderTail(
                $result->tail,
                $formatter,
                $crop_for_section[SectionLayout::SECTION_TAIL] ?? 0,
            );
        }

        return $sections;
    }

    /**
     * Resolve each section's crop budget from its position in the layout.
     *
     * Sections not present in the layout aren't rendered to the screen
     * either, so they don't need a crop entry.
     *
     * @param array<int, list<int>> $row_crop_widths
     * @return array<string, int>
     */
    private function buildSectionCropMap(
        SectionLayout $layout,
        array $row_crop_widths,
    ): array {
        $map = [];
        foreach ($layout->rows as $row_index => $row) {
            foreach ($row as $col_index => $name) {
                $map[$name] = $row_crop_widths[$row_index][$col_index] ?? 0;
            }
        }
        return $map;
    }

    /**
     * @param list<array{frames: list<string>, annotations: array<string, string>|null}> $tail
     * @return list<string>
     */
    private function renderTail(array $tail, FrameFormatter $formatter, int $crop_width): array
    {
        $lines = [];
        $lines[] = '# last ' . count($tail) . ' sample(s)';
        if ($tail === []) {
            $lines[] = '  (no samples)';
            return $this->applyCrop($lines, $crop_width);
        }
        $count = count($tail);
        // "  [NN] " prefix is 7 chars (2 indent + 2 bracket + 2 digits + 1 space).
        // Bake that into the frame budget so --crop-anchor actually bites the
        // frame text rather than a late line-level crop eating the tail end.
        $frame_budget = $crop_width > 0 ? max(1, $crop_width - 7) : 0;
        foreach ($tail as $i => $entry) {
            $offset = $count - $i - 1;
            $label = $count === 1
                ? '(now)'
                : ($offset === 0 ? '(now)' : sprintf('(%d ago)', $offset));
            $lines[] = "  ── sample {$label} ──";
            foreach ($entry['frames'] as $depth => $frame) {
                $lines[] = sprintf('  [%2d] %s', $depth, $formatter->cropFrame($frame, $frame_budget));
            }
            if (($entry['annotations'] ?? null) !== null && $entry['annotations'] !== []) {
                foreach ($entry['annotations'] as $key => $value) {
                    $lines[] = sprintf('  # %s = %s', $key, $value);
                }
            }
        }
        // Safety net: the sample separator line and annotations are
        // fixed-format and normally short, but line-crop them anyway in
        // case someone feeds an absurdly wide annotation value.
        return $this->applyCrop($lines, $crop_width);
    }

    /**
     * @param array<string, int> $counts
     * @return list<string>
     */
    private function renderTable(
        string $title,
        array $counts,
        int $denominator,
        int $top,
        FrameFormatter $formatter,
        int $crop_width,
    ): array {
        arsort($counts);
        $rows = array_slice($counts, 0, $top, preserve_keys: true);

        $lines = [];
        $lines[] = FrameFormatter::cropLine("# {$title}", $crop_width);

        if ($rows === []) {
            $lines[] = FrameFormatter::cropLine('  (no matches)', $crop_width);
            return $lines;
        }

        // Pre-size the frame column: we crop each frame *before* handing
        // it to Symfony's Table, so the Table then auto-sizes its borders
        // around the already-short content. That preserves the right-hand
        // `|` border instead of having a post-render line crop eat it.
        //
        // Overhead per row: "| " + count + " | " + pct + " | " + frame + " |"
        // count column widens to max(len("count"), widest numeric value);
        // pct is fixed at 6 chars ("100.0%").
        $frame_budget = 0;
        if ($crop_width > 0) {
            $count_col = strlen('count');
            foreach ($rows as $row_count) {
                $count_col = max($count_col, strlen((string) $row_count));
            }
            $pct_col = 6; // "100.0%"
            $fixed_overhead = 2 + $count_col + 3 + $pct_col + 3 + 2;
            $frame_budget = max(1, $crop_width - $fixed_overhead);
        }

        $buffer = new BufferedOutput();
        $denom = (float)max(1, $denominator);
        $table = new Table($buffer);
        $table->setHeaders(['count', 'pct', 'frame']);
        foreach ($rows as $key => $count) {
            $pct = (float)$count * 100.0 / $denom;
            $frame_cell = $frame_budget > 0
                ? $formatter->cropFrame($key, $frame_budget)
                : $formatter->formatFrame($key);
            $table->addRow([
                (string) $count,
                sprintf('%5.1f%%', $pct),
                $frame_cell,
            ]);
        }
        $table->render();

        $rendered = rtrim($buffer->fetch(), "\n");
        if ($rendered !== '') {
            foreach (explode("\n", $rendered) as $row_line) {
                $lines[] = $row_line;
            }
        }
        // Note: no line-level applyCrop() on table rows — the frames are
        // already cropped, and Symfony's Table has sized the borders to
        // match. Clipping here would chew off the right border.
        return $lines;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function applyCrop(array $lines, int $crop_width): array
    {
        if ($crop_width <= 0) {
            return $lines;
        }
        $out = [];
        foreach ($lines as $line) {
            $out[] = FrameFormatter::cropLine($line, $crop_width);
        }
        return $out;
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
}
