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

namespace Reli\Rbt\Analyze;

/**
 * Parses the `--sections` layout spec and assembles pre-rendered section
 * bodies into the final text report.
 *
 * Spec syntax:
 *   - `,` separates rows (vertical stacking, top to bottom).
 *   - `+` separates columns within a row (horizontal stacking).
 *
 * Examples:
 *   - `tail,self,total`          → three rows, stacked (current default).
 *   - `self+total,tail`          → self/total side by side, tail full-width below.
 *   - `self+total+tail`          → three columns in one row.
 *
 * Recognised section names: `self`, `total`, `callers`, `callees`, `tail`.
 * Unknown names are rejected at parse time so typos fail loudly instead
 * of silently dropping a table.
 */
final class SectionLayout
{
    public const SECTION_SELF = 'self';
    public const SECTION_TOTAL = 'total';
    public const SECTION_CALLERS = 'callers';
    public const SECTION_CALLEES = 'callees';
    public const SECTION_TAIL = 'tail';

    public const KNOWN_SECTIONS = [
        self::SECTION_SELF,
        self::SECTION_TOTAL,
        self::SECTION_CALLERS,
        self::SECTION_CALLEES,
        self::SECTION_TAIL,
    ];

    public const DEFAULT_SPEC = 'self,total,callers,callees,tail';

    public const COLUMN_GAP = '  ';

    /**
     * @param list<list<string>> $rows each inner list is one row of section names
     */
    public function __construct(
        public readonly array $rows,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on unknown section names
     */
    public static function parse(string $spec): self
    {
        /** @var list<list<string>> $rows */
        $rows = [];
        foreach (explode(',', $spec) as $row_spec) {
            $row_spec = trim($row_spec);
            if ($row_spec === '') {
                continue;
            }
            $columns = [];
            foreach (explode('+', $row_spec) as $name) {
                $name = trim($name);
                if ($name === '') {
                    continue;
                }
                if (!in_array($name, self::KNOWN_SECTIONS, true)) {
                    throw new \InvalidArgumentException(
                        "unknown section '{$name}' in --sections "
                        . '(known: ' . implode(', ', self::KNOWN_SECTIONS) . ')',
                    );
                }
                $columns[] = $name;
            }
            if ($columns !== []) {
                $rows[] = $columns;
            }
        }
        return new self($rows);
    }

    /**
     * Assemble a flat list of output lines.
     *
     * `$sections` maps section name → rendered body (list of plain text lines,
     * no ANSI/format tags). A missing key or an empty list means "section
     * wasn't produced this run" — it's silently dropped from its row. Rows
     * that end up empty after dropping missing sections are skipped whole
     * (no dangling blank line).
     *
     * @param array<string, list<string>> $sections
     * @return list<string>
     */
    public function assemble(array $sections): array
    {
        $output = [];
        $first = true;
        foreach ($this->rows as $row) {
            $columns = [];
            foreach ($row as $name) {
                $lines = $sections[$name] ?? [];
                if ($lines !== []) {
                    $columns[] = $lines;
                }
            }
            if ($columns === []) {
                continue;
            }
            if (!$first) {
                $output[] = '';
            }
            $first = false;
            foreach ($this->combineRow($columns) as $line) {
                $output[] = $line;
            }
        }
        return $output;
    }

    /**
     * Horizontally zip already-rendered columns into a single block.
     *
     * Each column is left-aligned to its own max width; shorter columns
     * are bottom-padded with blank lines so neighbouring columns don't
     * smear into them. Trailing whitespace is stripped so terminals
     * don't paint spurious background colour past the last column.
     *
     * @param list<list<string>> $columns
     * @return list<string>
     */
    private function combineRow(array $columns): array
    {
        if (count($columns) === 1) {
            return $columns[0];
        }

        $height = 0;
        $widths = [];
        foreach ($columns as $col) {
            $height = max($height, count($col));
            $widths[] = self::columnWidth($col);
        }

        $lines = [];
        $last = count($columns) - 1;
        for ($i = 0; $i < $height; $i++) {
            $parts = [];
            foreach ($columns as $ci => $col) {
                $cell = $col[$i] ?? '';
                if ($ci !== $last) {
                    $pad = $widths[$ci] - mb_strlen($cell);
                    if ($pad > 0) {
                        $cell .= str_repeat(' ', $pad);
                    }
                }
                $parts[] = $cell;
            }
            $lines[] = rtrim(implode(self::COLUMN_GAP, $parts));
        }
        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private static function columnWidth(array $lines): int
    {
        $w = 0;
        foreach ($lines as $line) {
            $lw = mb_strlen($line);
            if ($lw > $w) {
                $w = $lw;
            }
        }
        return $w;
    }
}
