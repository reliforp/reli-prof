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

namespace Reli\Inspector\Output\MemoryOutput\Report\Formatter;

use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;
use Reli\Inspector\Output\MemoryOutput\Report\ReportResult;

final class TextReportFormatter implements ReportFormatterInterface
{
    /** @psalm-suppress InvalidOperand */
    #[\Override]
    public function format(ReportResult $result): string
    {
        $lines = [];
        $sep = str_repeat('=', 70);

        $lines[] = $sep;
        $lines[] = ' reli-prof Memory Analysis Report';
        $lines[] = $sep;
        $lines[] = '';

        // Group findings by section
        $overview = [];
        $actionable = [];
        $info = [];

        foreach ($result->findings as $finding) {
            if ($finding->kind === 'overview' || $finding->kind === 'coverage_gap') {
                $overview[] = $finding;
            } elseif (
                in_array($finding->kind, ['root_blame', 'retained_exact', 'retained_approximate'], true)
            ) {
                $info[] = $finding;
            } elseif ($finding->severity === FindingSeverity::Info) {
                $info[] = $finding;
            } else {
                $actionable[] = $finding;
            }
        }

        // Overview section
        if ($overview !== []) {
            $lines[] = '=== Overview ===';
            foreach ($overview as $finding) {
                $lines[] = '  ' . $finding->summary;
            }
            $lines[] = '';
        }

        // Actionable findings (sorted by severity)
        if ($actionable !== []) {
            usort(
                $actionable,
                fn(Finding $a, Finding $b) =>
                    self::severityOrder($a->severity) <=> self::severityOrder($b->severity)
            );

            $lines[] = '=== Findings ===';
            $lines[] = '';

            foreach ($actionable as $finding) {
                $tag = strtoupper($finding->severity->value);
                $lines[] = "  [{$tag}] {$finding->kind}: {$finding->summary}";

                if ($finding->hypothesis !== '') {
                    $lines[] = "    {$finding->hypothesis}";
                }

                if ($finding->next_checks !== []) {
                    $lines[] = '    Next: ' . implode('; ', $finding->next_checks);
                }

                $lines[] = '';
            }
        }

        // Blame allocation
        $blame_findings = array_filter($info, fn(Finding $f) => $f->kind === 'root_blame');
        if ($blame_findings !== []) {
            $lines[] = '=== Root Blame Allocation ===';
            $lines[] = '';
            $lines[] = sprintf('  %-25s %10s %10s %10s %8s', 'Root Branch', 'Exclusive', 'Shared', 'Total', '% Heap');
            $lines[] = '  ' . str_repeat('-', 70);

            foreach ($blame_findings as $finding) {
                $facts = $finding->facts;
                /** @var string $root_name */
                $root_name = $facts['root_name'] ?? '?';
                /** @var int|float $exclusive */
                $exclusive = $facts['exclusive_bytes'] ?? 0;
                /** @var int|float $shared */
                $shared = $facts['shared_bytes'] ?? 0;
                /** @var int|float $total */
                $total = $facts['total_bytes'] ?? 0;
                /** @var float $pct */
                $pct = $facts['percentage'] ?? 0;
                $lines[] = sprintf(
                    '  %-25s %9.2fM %9.2fM %9.2fM %7.1f%%',
                    $root_name,
                    $exclusive / 1024 / 1024,
                    $shared / 1024 / 1024,
                    $total / 1024 / 1024,
                    $pct,
                );
            }
            $lines[] = '';
        }

        // Info findings (non-blame)
        $other_info = array_filter($info, fn(Finding $f) => $f->kind !== 'root_blame');
        if ($other_info !== []) {
            $lines[] = '=== Additional Info ===';
            foreach ($other_info as $finding) {
                $lines[] = "  [{$finding->kind}] {$finding->summary}";
            }
            $lines[] = '';
        }

        $lines[] = $sep;

        return implode("\n", $lines) . "\n";
    }

    private static function severityOrder(FindingSeverity $severity): int
    {
        return match ($severity) {
            FindingSeverity::High => 0,
            FindingSeverity::Warning => 1,
            FindingSeverity::Medium => 2,
            FindingSeverity::Low => 3,
            FindingSeverity::Info => 4,
        };
    }
}
