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
 * Modes:
 * - List: flat list (roots or TopN retained)
 * - Sandwich: parents | focus | children (3-pane)
 */
final class RmemExploreTui
{
    private bool $running = false;

    // ---- List mode state ----
    /** @var list<array{node_id: int, retained: int, shallow: int, label: string, link_name?: string}> */
    private array $rows = [];
    private int $selected = 0;
    private int $topRow = 0;
    /** @var list<array<string, mixed>> focus stack for list drill-down */
    private array $focusStack = [];
    private ?int $focusNodeId = null;
    private string $focusLabel = 'Roots';

    // ---- Sandwich mode state ----
    private bool $sandwich = false;
    private int $sandwichNodeId = 0;
    private string $sandwichLabel = '';
    /** @var list<array{node_id: int, retained: int, shallow: int, label: string, link_name?: string}> */
    private array $parentRows = [];
    /** @var list<array{node_id: int, retained: int, shallow: int, label: string, link_name?: string}> */
    private array $childRows = [];
    private int $parentSelected = 0;
    private int $parentTopRow = 0;
    private int $childSelected = 0;
    private int $childTopRow = 0;
    /** 'parents' | 'children' */
    private string $activePane = 'children';
    /** @var list<array{node_id: int, label: string, pane: string}> sandwich history */
    private array $sandwichHistory = [];

    private bool $showHelp = false;
    private bool $showSidebar = true;

    public function __construct(
        private RmemModel $model,
        private TerminalInterface $term,
        private Keymap $keymap,
    ) {
    }

    public function run(): void
    {
        $this->running = true;
        $this->rows = $this->model->getRootChildren();

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
            Keymap::ACTION_FOCUS_ENTER => $this->enter(),
            Keymap::ACTION_BACK => $this->back(),
            Keymap::ACTION_TOGGLE_PANE => $this->togglePane(),
            Keymap::ACTION_VIEW_SELF => $this->switchToTopRetained(),
            Keymap::ACTION_VIEW_TOTAL => $this->switchToRoots(),
            Keymap::ACTION_TOGGLE_OVERVIEW => $this->showSidebar = !$this->showSidebar,
            Keymap::ACTION_HELP => $this->showHelp = true,
            Keymap::ACTION_QUIT => $this->running = false,
            default => null,
        };
    }

    // ---- Navigation ----

    private function moveSelection(int $delta): void
    {
        if ($this->sandwich) {
            $this->moveSandwichSelection($delta);
            return;
        }
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

    private function moveSandwichSelection(int $delta): void
    {
        $halfH = (int)($this->bodyHeight() / 2);
        if ($this->activePane === 'parents') {
            $count = count($this->parentRows);
            if ($count === 0) {
                return;
            }
            $this->parentSelected = max(0, min($count - 1, $this->parentSelected + $delta));
            if ($this->parentSelected < $this->parentTopRow) {
                $this->parentTopRow = $this->parentSelected;
            } elseif ($this->parentSelected >= $this->parentTopRow + $halfH) {
                $this->parentTopRow = $this->parentSelected - $halfH + 1;
            }
        } else {
            $count = count($this->childRows);
            if ($count === 0) {
                return;
            }
            $this->childSelected = max(0, min($count - 1, $this->childSelected + $delta));
            if ($this->childSelected < $this->childTopRow) {
                $this->childTopRow = $this->childSelected;
            } elseif ($this->childSelected >= $this->childTopRow + $halfH) {
                $this->childTopRow = $this->childSelected - $halfH + 1;
            }
        }
    }

    private function enter(): void
    {
        if ($this->sandwich) {
            $this->sandwichEnter();
            return;
        }
        if (!isset($this->rows[$this->selected])) {
            return;
        }
        $row = $this->rows[$this->selected];
        $this->enterSandwich($row['node_id'], $row['label']);
    }

    private function sandwichEnter(): void
    {
        if ($this->activePane === 'children') {
            if (isset($this->childRows[$this->childSelected])) {
                $row = $this->childRows[$this->childSelected];
                $this->enterSandwich($row['node_id'], $row['label']);
            }
        } else {
            if (isset($this->parentRows[$this->parentSelected])) {
                $row = $this->parentRows[$this->parentSelected];
                $this->enterSandwich($row['node_id'], $row['label']);
            }
        }
    }

    private function enterSandwich(int $nodeId, string $label): void
    {
        if ($this->sandwich) {
            $this->sandwichHistory[] = [
                'node_id' => $this->sandwichNodeId,
                'label' => $this->sandwichLabel,
                'pane' => $this->activePane,
            ];
        }
        $this->sandwich = true;
        $this->sandwichNodeId = $nodeId;
        $this->sandwichLabel = $label;
        $this->parentRows = $this->model->getParents($nodeId);
        $this->childRows = $this->model->getChildren($nodeId);
        $this->parentSelected = 0;
        $this->parentTopRow = 0;
        $this->childSelected = 0;
        $this->childTopRow = 0;
        $this->activePane = 'children';
    }

    private function back(): void
    {
        if ($this->sandwich && $this->sandwichHistory !== []) {
            // History back within sandwich
            $prev = array_pop($this->sandwichHistory);
            $this->enterSandwichDirect($prev['node_id'], $prev['label']);
            $this->activePane = $prev['pane'];
            return;
        }
        if ($this->sandwich) {
            // Exit sandwich to list
            $this->sandwich = false;
            $this->sandwichHistory = [];
            return;
        }
        if ($this->focusStack === []) {
            return;
        }
        $prev = array_pop($this->focusStack);
        $prevNodeId = $prev['node_id'];
        if ($prevNodeId === -1) {
            $this->focusNodeId = null;
            $this->focusLabel = 'Roots';
            $this->rows = $this->model->getRootChildren();
        } else {
            $this->focusNodeId = $prevNodeId;
            $this->focusLabel = $prev['label'];
            $this->rows = $this->model->getChildren($prevNodeId);
        }
        $this->selected = $prev['selected'] ?? 0;
        $this->topRow = $prev['topRow'] ?? 0;
    }

    /** Set sandwich focus without pushing history (used by back) */
    private function enterSandwichDirect(int $nodeId, string $label): void
    {
        $this->sandwichNodeId = $nodeId;
        $this->sandwichLabel = $label;
        $this->parentRows = $this->model->getParents($nodeId);
        $this->childRows = $this->model->getChildren($nodeId);
        $this->parentSelected = 0;
        $this->parentTopRow = 0;
        $this->childSelected = 0;
        $this->childTopRow = 0;
    }

    private function togglePane(): void
    {
        if (!$this->sandwich) {
            return;
        }
        $this->activePane = $this->activePane === 'children' ? 'parents' : 'children';
    }

    private function switchToTopRetained(): void
    {
        $this->sandwich = false;
        $this->focusStack = [];
        $this->focusNodeId = null;
        $this->focusLabel = 'Top retained';
        $this->rows = $this->model->getTopRetained(10000);
        $this->selected = 0;
        $this->topRow = 0;
    }

    private function switchToRoots(): void
    {
        $this->sandwich = false;
        $this->focusStack = [];
        $this->focusNodeId = null;
        $this->focusLabel = 'Roots';
        $this->rows = $this->model->getRootChildren();
        $this->selected = 0;
        $this->topRow = 0;
    }

    // ---- Rendering ----

    private function render(): void
    {
        [$cols, $rows] = $this->term->size();

        $sidebarW = 0;
        $sidebarLines = [];
        if ($this->showSidebar && $cols > 80) {
            $sidebarW = min(40, (int)($cols * 0.3));
            $focusId = $this->sandwich ? $this->sandwichNodeId : ($this->rows[$this->selected]['node_id'] ?? null);
            if ($focusId !== null) {
                $sidebarLines = $this->buildSidebarLines($focusId, $sidebarW, $rows);
            }
        }
        $mainW = $cols - $sidebarW;

        $lines = [];
        $lines[] = $this->renderHeader($cols);

        if ($this->sandwich) {
            $this->renderSandwich($lines, $mainW, $rows);
        } else {
            $this->renderList($lines, $mainW, $rows);
        }

        // Merge sidebar
        if ($sidebarW > 0 && $sidebarLines !== []) {
            $sep = "\e[2m│\e[22m";
            for ($i = 1; $i < count($lines); $i++) {
                $sLine = $sidebarLines[$i - 1] ?? '';
                // Pad main line to mainW, then append separator + sidebar
                $mainLine = $lines[$i];
                // Strip trailing spaces/escapes for clean padding
                $lines[$i] = $mainLine . $sep . str_pad($sLine, $sidebarW);
            }
        }

        if ($this->showHelp) {
            $this->renderHelpOverlay($lines, $cols, $rows);
        }

        $this->term->clear();
        $this->term->write(implode("\n", $lines));
    }

    /**
     * Build sidebar lines showing node detail + path-to-root.
     * @return list<string>
     */
    private function buildSidebarLines(int $nodeId, int $width, int $totalRows): array
    {
        $lines = [];
        $detail = $this->model->nodeDetail($nodeId);
        $trunc = fn (string $s): string => strlen($s) > $width - 2
            ? substr($s, 0, $width - 5) . '...'
            : $s;

        // Node detail
        $lines[] = "\e[1m Node detail:\e[0m";
        $lines[] = ' ' . $trunc("type: {$detail['type']}");
        if ($detail['class'] !== null) {
            $lines[] = ' ' . $trunc("class: {$detail['class']}");
        }
        $lines[] = ' ' . $trunc("shallow: " . SizeFormatter::format($detail['shallow']));
        $lines[] = ' ' . $trunc("retained: " . SizeFormatter::format($detail['retained']));
        if ($detail['address'] !== null) {
            $lines[] = ' ' . $trunc("addr: 0x" . dechex($detail['address']));
        }
        if ($detail['string_value'] !== null) {
            $preview = $detail['string_value'];
            if (strlen($preview) > $width - 10) {
                $preview = substr($preview, 0, $width - 13) . '...';
            }
            $lines[] = ' ' . $trunc("val: \"{$preview}\"");
        }
        foreach ($detail['attributes'] as $key => $val) {
            $lines[] = ' ' . $trunc("{$key}: {$val}");
        }

        $lines[] = '';
        $lines[] = "\e[1m Path to root:\e[0m";

        $path = $this->model->pathToRoot($nodeId);
        foreach ($path as $i => $step) {
            $indent = str_repeat(' ', min($i, 8));
            $link = $step['link_name'];
            $label = $step['label'];
            $text = "{$indent}[{$link}] {$label}";
            $lines[] = ' ' . $trunc($text);
            if (count($lines) >= $totalRows - 3) {
                $lines[] = ' ...';
                break;
            }
        }

        while (count($lines) < $totalRows - 2) {
            $lines[] = '';
        }

        return $lines;
    }

    private function renderList(array &$lines, int $cols, int $totalRows): void
    {
        $lines[] = $this->renderBreadcrumb($cols);
        $lines[] = $this->colHeader($cols);
        $lines[] = '  ' . str_repeat('─', min($cols - 2, 120));

        $bodyH = $totalRows - count($lines) - 2;
        $count = count($this->rows);
        for ($i = $this->topRow; $i < min($this->topRow + $bodyH, $count); $i++) {
            $lines[] = $this->formatRow($this->rows[$i], $i === $this->selected, $cols);
        }

        while (count($lines) < $totalRows - 2) {
            $lines[] = '';
        }

        $lines[] = $this->renderFooter($cols);
        $lines[] = sprintf(
            ' %s nodes | %s total | depth %d',
            number_format(count($this->rows)),
            SizeFormatter::format($this->model->nodeSizesSum()),
            count($this->focusStack),
        );
    }

    private function renderSandwich(array &$lines, int $cols, int $totalRows): void
    {
        $bodyH = $totalRows - 4; // header + focus bar + footer + status
        $halfH = (int)($bodyH / 2);

        // Parents pane
        $pLabel = $this->activePane === 'parents' ? "\e[1m▸ Parents\e[0m" : "\e[2m  Parents\e[0m";
        $lines[] = $pLabel . sprintf(' (%d)', count($this->parentRows));
        $pCount = count($this->parentRows);
        for ($i = $this->parentTopRow; $i < min($this->parentTopRow + $halfH, $pCount); $i++) {
            $sel = $this->activePane === 'parents' && $i === $this->parentSelected;
            $lines[] = $this->formatRow($this->parentRows[$i], $sel, $cols);
        }
        while (count($lines) < $halfH + 2) {
            $lines[] = '';
        }

        // Focus bar
        $detail = $this->model->nodeDetail($this->sandwichNodeId);
        $nodeInfo = $detail['type'];
        if ($detail['class'] !== null) {
            $nodeInfo .= ': ' . $detail['class'];
        }
        $nodeInfo .= sprintf(
            ' | shallow %s | retained %s | node#%d',
            SizeFormatter::format($detail['shallow']),
            SizeFormatter::format($detail['retained']),
            $this->sandwichNodeId,
        );
        if (strlen($nodeInfo) > $cols - 2) {
            $nodeInfo = substr($nodeInfo, 0, $cols - 5) . '...';
        }
        $lines[] = "\e[1;43;30m " . str_pad($nodeInfo, $cols - 1) . "\e[0m";

        // Children pane
        $cLabel = $this->activePane === 'children' ? "\e[1m▸ Children\e[0m" : "\e[2m  Children\e[0m";
        $lines[] = $cLabel . sprintf(' (%d)', count($this->childRows));
        $remainH = $totalRows - count($lines) - 2;
        $cCount = count($this->childRows);
        for ($i = $this->childTopRow; $i < min($this->childTopRow + $remainH, $cCount); $i++) {
            $sel = $this->activePane === 'children' && $i === $this->childSelected;
            $lines[] = $this->formatRow($this->childRows[$i], $sel, $cols);
        }

        while (count($lines) < $totalRows - 2) {
            $lines[] = '';
        }

        $lines[] = $this->renderFooter($cols);
        $lines[] = sprintf(
            ' node#%d | Tab:switch pane | Enter:focus | Bksp:back to list',
            $this->sandwichNodeId,
        );
    }

    /** @param array{node_id: int, retained: int, shallow: int, label: string, link_name?: string} $row */
    private function formatRow(array $row, bool $selected, int $cols): string
    {
        $retained = SizeFormatter::format($row['retained']);
        $shallow = SizeFormatter::format($row['shallow']);
        $link = isset($row['link_name']) ? $row['link_name'] . ' → ' : '';
        $label = $link . $row['label'];
        $maxLabel = max(10, $cols - 30);
        if (strlen($label) > $maxLabel) {
            $label = substr($label, 0, $maxLabel - 3) . '...';
        }

        $prefix = $selected ? "\e[7m" : '';
        $suffix = $selected ? "\e[0m" : '';
        return sprintf(
            "%s  %12s %12s  %-*s%s",
            $prefix,
            $retained,
            $shallow,
            $maxLabel,
            $label,
            $suffix,
        );
    }

    private function colHeader(int $cols): string
    {
        return sprintf(
            "\e[1m  %12s %12s  %-*s\e[0m",
            'Retained',
            'Shallow',
            max(10, $cols - 30),
            $this->focusNodeId !== null ? 'Link → Label' : 'Label',
        );
    }

    private function renderHeader(int $cols): string
    {
        $title = " rmem:explore";
        $mode = $this->sandwich ? '[sandwich]' : '[list]';
        $info = sprintf(
            "%s %s nodes, %s edges ",
            $mode,
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
        $hints = $this->sandwich
            ? ' ↑↓:select  Tab:pane  Enter:focus  Bksp:back  o:sidebar  s:top  t:roots  ?:help  q:quit'
            : ' ↑↓:select  Enter:sandwich  Bksp:back  o:sidebar  s:top-retained  t:roots  ?:help  q:quit';
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
            '    Enter           Open sandwich view / change focus',
            '    Backspace/←     Go back',
            '    Tab             Switch pane (sandwich mode)',
            '',
            '  Views:',
            '    s               Top retained ranking',
            '    t               Root branches',
            '    o               Toggle sidebar (path to root)',
            '',
            '  Sandwich view:',
            '    Top pane        Parents (who retains this node)',
            '    Focus bar       Selected node details',
            '    Bottom pane     Children (what this node retains)',
            '',
            '  Other:',
            '    ?               Toggle this help',
            '    q               Quit',
        ];

        $boxW = 60;
        $boxH = count($help) + 2;
        $startRow = max(0, (int)(($rows - $boxH) / 2));

        for ($i = 0; $i < $boxH; $i++) {
            $lineIdx = $startRow + $i;
            if ($lineIdx >= count($lines)) {
                break;
            }
            if ($i === 0 || $i === $boxH - 1) {
                $content = str_repeat('─', $boxW);
            } else {
                $text = $help[$i - 1] ?? '';
                $content = str_pad($text, $boxW);
            }
            $startCol = max(0, (int)(($cols - $boxW) / 2));
            $lines[$lineIdx] = "\e[" . ($lineIdx + 1) . ";" . ($startCol + 1) . "H"
                . "\e[47;30m" . $content . "\e[0m";
        }
    }

    private function bodyHeight(): int
    {
        [, $rows] = $this->term->size();
        return max(1, $rows - 6);
    }
}
