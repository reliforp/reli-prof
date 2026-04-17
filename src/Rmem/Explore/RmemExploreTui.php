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

namespace Reli\Rmem\Explore;

use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;
use Reli\Rbt\Explore\Keymap;
use Reli\Rbt\Explore\TerminalInterface;

/**
 * Interactive TUI for browsing a .rmem memory snapshot.
 *
 * Prototype: TopN retained list + children drill-down + path to root.
 */
final class RmemExploreTui
{
    private bool $running = false;

    /** @var list<array{node_id: int, retained: int, shallow: int, label: string, link_name?: string}> */
    private array $rows = [];
    private int $selected = 0;
    private int $topRow = 0;

    /** @var list<array{node_id: int, label: string}> focus stack */
    private array $focusStack = [];

    private ?int $focusNodeId = null;
    private string $focusLabel = 'Top retained';

    private bool $showHelp = false;

    public function __construct(
        private RmemModel $model,
        private TerminalInterface $term,
        private Keymap $keymap,
    ) {
    }

    public function run(): void
    {
        $this->running = true;
        $this->rows = $this->model->getTopRetained(10000);

        $this->term->enter();
        try {
            while ($this->running) {
                $this->render();
                $key = $this->term->pollKey();
                if ($key === null) {
                    continue;
                }
                $this->dispatch($key);
            }
        } finally {
            $this->term->leave();
        }
    }

    private function dispatch(string $key): void
    {
        $action = $this->keymap->resolve($key);

        if ($this->showHelp) {
            $this->showHelp = false;
            return;
        }

        match ($action) {
            Keymap::ACTION_UP => $this->moveSelection(-1),
            Keymap::ACTION_DOWN => $this->moveSelection(1),
            Keymap::ACTION_PAGE_UP => $this->moveSelection(-$this->bodyHeight()),
            Keymap::ACTION_PAGE_DOWN => $this->moveSelection($this->bodyHeight()),
            Keymap::ACTION_HOME => $this->moveSelection(-999999),
            Keymap::ACTION_END => $this->moveSelection(999999),
            Keymap::ACTION_FOCUS_ENTER => $this->drillInto(),
            Keymap::ACTION_BACK => $this->drillOut(),
            Keymap::ACTION_HELP => $this->showHelp = true,
            Keymap::ACTION_QUIT => $this->running = false,
            default => null,
        };
    }

    private function moveSelection(int $delta): void
    {
        $count = count($this->rows);
        if ($count === 0) {
            return;
        }
        $this->selected = max(0, min($count - 1, $this->selected + $delta));
        $bodyH = $this->bodyHeight();
        if ($this->selected < $this->topRow) {
            $this->topRow = $this->selected;
        } elseif ($this->selected >= $this->topRow + $bodyH) {
            $this->topRow = $this->selected - $bodyH + 1;
        }
    }

    private function drillInto(): void
    {
        if (!isset($this->rows[$this->selected])) {
            return;
        }
        $row = $this->rows[$this->selected];
        $nodeId = $row['node_id'];

        $this->focusStack[] = [
            'node_id' => $this->focusNodeId ?? -1,
            'label' => $this->focusLabel,
            'selected' => $this->selected,
            'topRow' => $this->topRow,
        ];

        $this->focusNodeId = $nodeId;
        $this->focusLabel = $row['label'];
        $this->rows = $this->model->getChildren($nodeId);
        $this->selected = 0;
        $this->topRow = 0;
    }

    private function drillOut(): void
    {
        if ($this->focusStack === []) {
            return;
        }
        $prev = array_pop($this->focusStack);
        $prevNodeId = $prev['node_id'];

        if ($prevNodeId === -1) {
            // Back to top
            $this->focusNodeId = null;
            $this->focusLabel = 'Top retained';
            $this->rows = $this->model->getTopRetained(10000);
        } else {
            $this->focusNodeId = $prevNodeId;
            $this->focusLabel = $prev['label'];
            $this->rows = $this->model->getChildren($prevNodeId);
        }
        $this->selected = $prev['selected'] ?? 0;
        $this->topRow = $prev['topRow'] ?? 0;
    }

    // ---- Rendering ----

    private function render(): void
    {
        [$cols, $rows] = $this->term->size();
        $lines = [];

        // Header
        $lines[] = $this->renderHeader($cols);
        $lines[] = $this->renderBreadcrumb($cols);

        // Column headers
        $lines[] = sprintf(
            "\e[1m  %12s %12s  %-*s\e[0m",
            'Retained',
            'Shallow',
            max(10, $cols - 30),
            $this->focusNodeId !== null ? 'Link → Label' : 'Label',
        );
        $lines[] = '  ' . str_repeat('─', min($cols - 2, 120));

        // Body
        $bodyH = $rows - count($lines) - 2; // -2 for footer + status
        $count = count($this->rows);
        for ($i = $this->topRow; $i < min($this->topRow + $bodyH, $count); $i++) {
            $row = $this->rows[$i];
            $retained = SizeFormatter::format($row['retained']);
            $shallow = SizeFormatter::format($row['shallow']);
            $link = isset($row['link_name']) ? $row['link_name'] . ' → ' : '';
            $label = $link . $row['label'];
            if (strlen($label) > $cols - 30) {
                $label = substr($label, 0, $cols - 33) . '...';
            }

            $prefix = $i === $this->selected ? "\e[7m" : '';
            $suffix = $i === $this->selected ? "\e[0m" : '';
            $lines[] = sprintf(
                "%s  %12s %12s  %-*s%s",
                $prefix,
                $retained,
                $shallow,
                max(10, $cols - 30),
                $label,
                $suffix,
            );
        }

        // Pad empty lines
        while (count($lines) < $rows - 2) {
            $lines[] = '';
        }

        // Footer
        $lines[] = $this->renderFooter($cols);
        $lines[] = sprintf(
            ' %s nodes | %s total | depth %d',
            number_format(count($this->rows)),
            SizeFormatter::format($this->model->nodeSizesSum()),
            count($this->focusStack),
        );

        // Help overlay
        if ($this->showHelp) {
            $this->renderHelpOverlay($lines, $cols, $rows);
        }

        $this->term->clear();
        $this->term->write(implode("\n", $lines));
    }

    private function renderHeader(int $cols): string
    {
        $title = " rmem:explore";
        $info = sprintf(
            "%s nodes, %s edges ",
            number_format($this->model->nodeCount),
            number_format($this->model->edgeCount),
        );
        $pad = max(0, $cols - strlen($title) - strlen($info));
        return "\e[1;44;37m" . $title . str_repeat(' ', $pad) . $info . "\e[0m";
    }

    private function renderBreadcrumb(int $cols): string
    {
        $parts = [];
        foreach ($this->focusStack as $entry) {
            $parts[] = $entry['label'];
        }
        $parts[] = $this->focusLabel;
        $breadcrumb = implode(' → ', $parts);
        if (strlen($breadcrumb) > $cols - 2) {
            $breadcrumb = '...' . substr($breadcrumb, -(($cols - 5)));
        }
        return "\e[33m {$breadcrumb}\e[0m";
    }

    private function renderFooter(int $cols): string
    {
        $hints = ' ↑↓:select  Enter:children  Backspace:back  ?:help  q:quit';
        $pad = max(0, $cols - strlen($hints));
        return "\e[2m" . $hints . str_repeat(' ', $pad) . "\e[0m";
    }

    /** @param list<string> &$lines */
    private function renderHelpOverlay(array &$lines, int $cols, int $rows): void
    {
        $help = [
            '  rmem:explore — Memory Snapshot Explorer',
            '',
            '  Navigation:',
            '    ↑/↓ or k/j     Select node',
            '    PgUp/PgDn      Page scroll',
            '    Home/End        Jump to top/bottom',
            '    Enter           Drill into children',
            '    Backspace/←     Go back to parent',
            '',
            '  Display:',
            '    Retained        Subtree size (node + all descendants)',
            '    Shallow         Node\'s own size only',
            '',
            '  Other:',
            '    ?               Toggle this help',
            '    q               Quit',
        ];

        $boxW = 56;
        $boxH = count($help) + 2;
        $startRow = max(0, ($rows - $boxH) / 2);
        $startCol = max(0, ($cols - $boxW) / 2);

        for ($i = 0; $i < $boxH; $i++) {
            $lineIdx = (int)$startRow + $i;
            if ($lineIdx >= count($lines)) {
                break;
            }
            if ($i === 0 || $i === $boxH - 1) {
                $content = str_repeat('─', $boxW);
            } else {
                $text = $help[$i - 1] ?? '';
                $content = str_pad($text, $boxW);
            }
            $before = substr($lines[$lineIdx], 0, (int)$startCol);
            // Simple overlay: replace the middle portion
            $lines[$lineIdx] = "\e[" . ($lineIdx + 1) . ";1H"
                . "\e[" . ($lineIdx + 1) . ";" . ((int)$startCol + 1) . "H"
                . "\e[47;30m" . $content . "\e[0m";
        }
    }

    private function bodyHeight(): int
    {
        [, $rows] = $this->term->size();
        return max(1, $rows - 6); // header(1) + breadcrumb(1) + col_header(2) + footer(1) + status(1)
    }
}
