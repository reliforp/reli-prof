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
    /** 'parents' | 'children' | 'sidebar' */
    private string $activePane = 'children';
    /** @var list<array{node_id: int, label: string, pane: string}> sandwich history */
    private array $sandwichHistory = [];
    private int $sidebarScroll = 0;

    private bool $showHelp = false;
    private bool $showSidebar = true;
    private ?string $filterPattern = null;
    private string $filterInput = '';
    private bool $filterPrompt = false;
    private bool $addrPrompt = false;
    private string $addrInput = '';
    /** @var list<array{node_id: int, retained: int, shallow: int, label: string, link_name?: string}> */
    private array $unfilteredRows = [];
    private bool $allEdges = true;
    /** 'retained' | 'link' */
    private string $sortMode = 'retained';

    /** @var 'normal'|'class_ranking'|'type_ranking'|'class_instances'|'type_instances' */
    private string $listMode = 'normal';
    private string $listModeParam = '';

    private int $spinnerFrame = 0;
    private string $lastRendered = '';
    private static array $SPINNER = ['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏'];
    private ?int $queryChildPid = null;
    private ?int $sccBuilderPid = null;
    /** @var array<int, string> node_id => label */
    private array $bookmarks = [];

    public function __construct(
        private RmemModel $model,
        private TerminalInterface $term,
        private Keymap $keymap,
        private ?int $initialNodeId = null,
        private ?string $socketPath = null,
    ) {
    }

    public function setQueryChildPid(int $pid): void
    {
        $this->queryChildPid = $pid;
    }

    public function setSccBuilderPid(int $pid): void
    {
        $this->sccBuilderPid = $pid;
    }

    public function run(): void
    {
        $this->running = true;
        $this->rows = $this->model->getRootChildren();

        if ($this->initialNodeId !== null) {
            $label = $this->model->nodeLabel($this->initialNodeId);
            $this->enterSandwich($this->initialNodeId, $label);
        }

        $useTimeout = $this->socketPath !== null || $this->sccBuilderPid !== null;

        $this->term->enter();
        try {
            while ($this->running) {
                $this->render();

                if ($useTimeout) {
                    $key = $this->term->pollKeyTimeout(100);
                    $this->spinnerFrame = ($this->spinnerFrame + 1) % 10;
                    $this->checkChildStatus();
                } else {
                    $key = $this->term->pollKey();
                }

                if ($key === null || $key === '') {
                    continue;
                }
                $this->dispatch($key);
            }
        } finally {
            $this->term->leave();
        }
    }

    private function checkChildStatus(): void
    {
        if (!function_exists('pcntl_waitpid')) {
            return;
        }
        if ($this->queryChildPid !== null) {
            $result = pcntl_waitpid($this->queryChildPid, $status, WNOHANG);
            if ($result > 0) {
                $this->queryChildPid = null;
            }
        }
        if ($this->sccBuilderPid !== null) {
            $result = pcntl_waitpid($this->sccBuilderPid, $status, WNOHANG);
            if ($result > 0) {
                $this->sccBuilderPid = null;
            }
        }
    }

    private function dispatch(string $key): void
    {
        $action = $this->keymap->resolve($key);

        if ($this->showHelp) {
            $this->showHelp = false;
            return;
        }

        if ($this->filterPrompt) {
            $this->handleFilterInput($key);
            return;
        }
        if ($this->addrPrompt) {
            $this->handleAddrInput($key);
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
            Keymap::ACTION_TOGGLE_PANE_REVERSE => $this->togglePane(true),
            Keymap::ACTION_VIEW_SELF => $this->switchToTopRetained(),
            Keymap::ACTION_VIEW_TOTAL => $this->switchToRoots(),
            Keymap::ACTION_TOGGLE_OVERVIEW => $this->showSidebar = !$this->showSidebar,
            Keymap::ACTION_NO_LINE => $this->toggleAllEdges(),
            Keymap::ACTION_FILTER_VIEW => $this->startFilter(),
            Keymap::ACTION_HELP => $this->showHelp = true,
            Keymap::ACTION_QUIT => $this->running = false,
            default => $this->dispatchRaw($key),
        };
    }

    // ---- Navigation ----

    private function moveSelection(int $delta): void
    {
        if ($this->activePane === 'sidebar') {
            $this->sidebarScroll = max(0, $this->sidebarScroll + $delta);
            return;
        }
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

        // Ranking → instance list drill-down
        if ($this->listMode === 'class_ranking' && isset($row['_class'])) {
            $className = $row['_class'];
            $this->focusLabel = "Class: {$className}";
            $this->listMode = 'class_instances';
            $this->listModeParam = $className;
            $this->rows = $this->model->getNodesByClass($className);
            $this->selected = 0;
            $this->topRow = 0;
            return;
        }
        if ($this->listMode === 'type_ranking' && isset($row['_type'])) {
            $typeName = $row['_type'];
            $this->focusLabel = "Type: {$typeName}";
            $this->listMode = 'type_instances';
            $this->listModeParam = $typeName;
            $this->rows = $this->model->getNodesByType($typeName);
            $this->selected = 0;
            $this->topRow = 0;
            return;
        }

        // SCC → member list
        if (isset($row['_scc_nodes'])) {
            $this->showSccMembers([
                'id' => $row['_scc_id'] ?? 0,
                'nodes' => $row['_scc_nodes'],
                'node_count' => count($row['_scc_nodes']),
                'total_size' => $row['retained'],
            ]);
            return;
        }

        $this->enterSandwich($row['node_id'], $this->model->nodeLabel($row['node_id']));
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
        $this->childRows = $this->model->getChildren($nodeId, $this->allEdges, $this->sortMode);
        $this->parentSelected = 0;
        $this->parentTopRow = 0;
        $this->childSelected = 0;
        $this->childTopRow = 0;
        $this->activePane = 'children';
        $this->sidebarScroll = 0;
        $this->filterPattern = null;
        $this->unfilteredRows = [];
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
        // Instance list → back to ranking
        if ($this->listMode === 'class_instances') {
            $this->switchToClassRanking();
            return;
        }
        if ($this->listMode === 'type_instances') {
            $this->switchToTypeRanking();
            return;
        }
        if ($this->listMode === 'scc_members') {
            if ($this->preSccState !== null) {
                // Restore state before SCC view
                $s = $this->preSccState;
                $this->sandwich = $s['sandwich'];
                $this->sandwichNodeId = $s['sandwichNodeId'];
                $this->sandwichLabel = $s['sandwichLabel'];
                $this->sandwichHistory = $s['sandwichHistory'];
                $this->rows = $s['rows'];
                $this->selected = $s['selected'];
                $this->topRow = $s['topRow'];
                $this->focusLabel = $s['focusLabel'];
                $this->listMode = $s['listMode'];
                $this->parentRows = $s['parentRows'];
                $this->childRows = $s['childRows'];
                $this->activePane = $s['activePane'];
                $this->preSccState = null;
                return;
            }
            $this->showCycles();
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
        $this->childRows = $this->model->getChildren($nodeId, $this->allEdges, $this->sortMode);
        $this->parentSelected = 0;
        $this->parentTopRow = 0;
        $this->childSelected = 0;
        $this->childTopRow = 0;
        $this->filterPattern = null;
        $this->unfilteredRows = [];
    }

    private function togglePane(bool $reverse = false): void
    {
        if (!$this->sandwich) {
            // In list mode, toggle between list and sidebar
            $this->activePane = $this->activePane === 'sidebar' ? 'children' : 'sidebar';
            return;
        }
        $panes = $this->showSidebar
            ? ['children', 'parents', 'sidebar']
            : ['children', 'parents'];
        if ($reverse) {
            $panes = array_reverse($panes);
        }
        $idx = array_search($this->activePane, $panes, true);
        $this->activePane = $panes[($idx + 1) % count($panes)];
    }

    private function toggleBookmark(): void
    {
        $nodeId = null;
        if ($this->sandwich) {
            if ($this->activePane === 'children' && isset($this->childRows[$this->childSelected])) {
                $nodeId = $this->childRows[$this->childSelected]['node_id'];
            } elseif ($this->activePane === 'parents' && isset($this->parentRows[$this->parentSelected])) {
                $nodeId = $this->parentRows[$this->parentSelected]['node_id'];
            } else {
                $nodeId = $this->sandwichNodeId;
            }
        } elseif (isset($this->rows[$this->selected])) {
            $nodeId = $this->rows[$this->selected]['node_id'];
        }
        if ($nodeId === null || $nodeId < 0) {
            return;
        }
        if (isset($this->bookmarks[$nodeId])) {
            unset($this->bookmarks[$nodeId]);
        } else {
            $this->bookmarks[$nodeId] = $this->model->nodeLabel($nodeId);
        }
    }

    private function showCycles(): void
    {
        // If current node belongs to an SCC, jump directly to its members
        $focusNodeId = null;
        if ($this->sandwich) {
            if ($this->activePane === 'children' && isset($this->childRows[$this->childSelected])) {
                $focusNodeId = $this->childRows[$this->childSelected]['node_id'];
            } elseif ($this->activePane === 'parents' && isset($this->parentRows[$this->parentSelected])) {
                $focusNodeId = $this->parentRows[$this->parentSelected]['node_id'];
            } else {
                $focusNodeId = $this->sandwichNodeId;
            }
        } elseif (isset($this->rows[$this->selected])) {
            $focusNodeId = $this->rows[$this->selected]['node_id'];
        }
        if ($focusNodeId !== null && $focusNodeId >= 0) {
            $sccId = $this->model->getNodeSccId($focusNodeId);
            if ($sccId !== null) {
                $profile = $this->model->getSccProfile($sccId);
                if ($profile !== null) {
                    $this->showSccMembers($profile);
                    return;
                }
            }
        }

        $profiles = $this->model->getSccProfiles();
        if ($profiles === null) {
            $this->sandwich = false;
            $this->focusLabel = $this->sccBuilderPid !== null
                ? 'Cycles — SCC still computing...'
                : 'Cycles — SCC not available (run report first or wait for builder)';
            $this->rows = [];
            $this->selected = 0;
            $this->topRow = 0;
            return;
        }
        if ($profiles === []) {
            $this->sandwich = false;
            $this->focusLabel = 'Cycles — no cycles found';
            $this->rows = [];
            $this->selected = 0;
            $this->topRow = 0;
            return;
        }

        $this->sandwich = false;
        $this->clearFilter();
        $this->focusStack = [];
        $this->focusLabel = sprintf('Cycles (%d SCCs)', count($profiles));
        $this->listMode = 'normal';
        $this->rows = [];

        usort($profiles, fn ($a, $b) => $b['total_size'] <=> $a['total_size']);

        foreach ($profiles as $profile) {
            $sig = $profile['signature'] ?? '';
            if (strlen($sig) > 60) {
                $sig = substr($sig, 0, 57) . '...';
            }
            $label = sprintf(
                'SCC#%d: %d nodes, %s — %s',
                $profile['id'],
                $profile['node_count'],
                SizeFormatter::format($profile['total_size']),
                $sig !== '' ? $sig : '(no classes)',
            );
            // Store SCC profile for drill-down into members
            $this->rows[] = [
                'node_id' => -1,
                'retained' => $profile['total_size'],
                'shallow' => $profile['node_count'],
                'label' => $label,
                'link_name' => 'SCC#' . $profile['id'],
                '_scc_nodes' => $profile['nodes'] ?? [],
                '_scc_id' => $profile['id'],
            ];
        }
        $this->selected = 0;
        $this->topRow = 0;
    }

    /** @var array<string, mixed>|null saved state before SCC member view */
    private ?array $preSccState = null;

    /** @param array{id: int, nodes: list<int>, node_count: int, total_size: int, signature?: string} $profile */
    private function showSccMembers(array $profile): void
    {
        // Save current state for back navigation
        $this->preSccState = [
            'sandwich' => $this->sandwich,
            'sandwichNodeId' => $this->sandwichNodeId,
            'sandwichLabel' => $this->sandwichLabel,
            'sandwichHistory' => $this->sandwichHistory,
            'rows' => $this->rows,
            'selected' => $this->selected,
            'topRow' => $this->topRow,
            'focusLabel' => $this->focusLabel,
            'listMode' => $this->listMode,
            'parentRows' => $this->parentRows,
            'childRows' => $this->childRows,
            'activePane' => $this->activePane,
        ];

        $sccId = $profile['id'];
        $this->sandwich = false;
        $this->clearFilter();
        $this->focusStack = [];
        $this->focusLabel = "SCC#{$sccId} members";
        $this->listMode = 'scc_members';
        $this->rows = [];
        foreach ($profile['nodes'] as $nid) {
            $this->rows[] = [
                'node_id' => $nid,
                'retained' => $this->model->subtreeSize($nid),
                'shallow' => $this->model->nodeSize($nid),
                'label' => $this->model->nodeLabel($nid),
            ];
        }
        usort($this->rows, fn ($a, $b) => $b['retained'] <=> $a['retained']);
        $this->selected = 0;
        $this->topRow = 0;
    }

    private function showSubtreeInfo(): void
    {
        $nodeId = null;
        if ($this->sandwich) {
            $nodeId = $this->sandwichNodeId;
        } elseif (isset($this->rows[$this->selected])) {
            $nodeId = $this->rows[$this->selected]['node_id'];
        }
        if ($nodeId === null || $nodeId < 0) {
            return;
        }

        // Compute subtree stats using RmemQueryService logic inline
        $typeGroups = [];
        $classGroups = [];
        $totalSize = 0;
        $scanned = 0;
        $maxNodes = 100000;
        $maxDepth = 50;

        $stack = [[$nodeId, 0]];
        $visited = [];
        while ($stack) {
            [$nid, $depth] = array_pop($stack);
            if (isset($visited[$nid]) || $depth > $maxDepth) {
                continue;
            }
            $visited[$nid] = true;
            $scanned++;
            if ($scanned > $maxNodes) {
                break;
            }
            $size = $this->model->nodeSize($nid);
            $totalSize += $size;

            $type = $this->model->nodeType($nid);
            $typeGroups[$type] = ($typeGroups[$type] ?? 0) + $size;

            $class = $this->model->resolveClassPublic($nid);
            if ($class !== null) {
                $classGroups[$class] = ($classGroups[$class] ?? 0) + $size;
            }

            foreach ($this->model->getChildrenRaw($nid) as $childId) {
                if (!isset($visited[$childId])) {
                    $stack[] = [$childId, $depth + 1];
                }
            }
        }

        arsort($typeGroups);
        arsort($classGroups);

        // Display as a list
        $this->sandwich = false;
        $this->clearFilter();
        $this->focusStack = [];
        $label = $this->model->nodeLabel($nodeId);
        $this->focusLabel = "Subtree: {$label} (" . SizeFormatter::format($totalSize) . ", {$scanned} nodes)";
        $this->listMode = 'normal';
        $this->rows = [];

        foreach (array_slice($typeGroups, 0, 20, true) as $type => $total) {
            $this->rows[] = [
                'node_id' => -1,
                'retained' => $total,
                'shallow' => 0,
                'label' => "[type] {$type}",
            ];
        }
        foreach (array_slice($classGroups, 0, 20, true) as $class => $total) {
            $this->rows[] = [
                'node_id' => -1,
                'retained' => $total,
                'shallow' => 0,
                'label' => "[class] {$class}",
            ];
        }
        $this->selected = 0;
        $this->topRow = 0;
    }

    private function switchToBookmarks(): void
    {
        if ($this->bookmarks === []) {
            return;
        }
        $this->sandwich = false;
        $this->clearFilter();
        $this->focusStack = [];
        $this->focusLabel = 'Bookmarks';
        $this->listMode = 'normal';
        $this->rows = [];
        foreach ($this->bookmarks as $nodeId => $label) {
            $this->rows[] = [
                'node_id' => $nodeId,
                'retained' => $this->model->subtreeSize($nodeId),
                'shallow' => $this->model->nodeSize($nodeId),
                'label' => $label,
            ];
        }
        usort($this->rows, fn (array $a, array $b) => $b['retained'] <=> $a['retained']);
        $this->selected = 0;
        $this->topRow = 0;
    }

    private function startAddressJump(): void
    {
        $this->addrPrompt = true;
        $this->addrInput = '';
    }

    private function handleAddrInput(string $key): void
    {
        if ($key === "\n" || $key === "\r") {
            $this->addrPrompt = false;
            $input = trim($this->addrInput);
            if ($input === '') {
                return;
            }
            // Parse as node ID or address
            if (str_starts_with($input, '0x') || str_starts_with($input, '0X')) {
                $addr = (int)hexdec(substr($input, 2));
                $nodeId = $this->model->findNodeByAddress($addr);
            } elseif (str_starts_with($input, '#')) {
                $nodeId = (int)substr($input, 1);
            } else {
                // Try as node ID first, then address
                $nodeId = (int)$input;
            }
            if ($nodeId !== null && $nodeId >= 0) {
                $this->enterSandwich($nodeId, $this->model->nodeLabel($nodeId));
            }
        } elseif ($key === "\e" || $key === "\x03") {
            $this->addrPrompt = false;
        } elseif ($key === "\x7f" || $key === "\x08") {
            $this->addrInput = substr($this->addrInput, 0, -1);
        } else {
            $c = ord($key);
            if ($c >= 32 && $c < 127) {
                $this->addrInput .= $key;
            }
        }
    }

    private function startFilter(): void
    {
        $this->filterPrompt = true;
        $this->filterInput = $this->filterPattern ?? '';
    }

    private function handleFilterInput(string $key): void
    {
        if ($key === "\n" || $key === "\r") {
            // Apply filter
            $this->filterPrompt = false;
            $pattern = trim($this->filterInput);
            if ($pattern === '') {
                $this->clearFilter();
            } else {
                $this->applyFilter($pattern);
            }
        } elseif ($key === "\e" || $key === "\x03") {
            // Cancel
            $this->filterPrompt = false;
        } elseif ($key === "\x7f" || $key === "\x08") {
            // Backspace
            $this->filterInput = substr($this->filterInput, 0, -1);
        } else {
            $c = ord($key);
            if ($c >= 32 && $c < 127) {
                $this->filterInput .= $key;
            }
        }
    }

    private function applyFilter(string $pattern): void
    {
        $this->filterPattern = $pattern;
        $lower = strtolower($pattern);

        // Save unfiltered rows if not already saved
        if ($this->unfilteredRows === []) {
            $this->unfilteredRows = $this->sandwich
                ? $this->childRows
                : $this->rows;
        }

        $source = $this->unfilteredRows;
        $filtered = [];
        foreach ($source as $row) {
            $label = $this->model->nodeLabel($row['node_id']);
            $link = $row['link_name'] ?? '';
            $haystack = strtolower($link . ' ' . $label);
            if (str_contains($haystack, $lower)) {
                $filtered[] = $row;
            }
        }

        if ($this->sandwich) {
            $this->childRows = $filtered;
            $this->childSelected = 0;
            $this->childTopRow = 0;
        } else {
            $this->rows = $filtered;
            $this->selected = 0;
            $this->topRow = 0;
        }
    }

    private function clearFilter(): void
    {
        $this->filterPattern = null;
        if ($this->unfilteredRows !== []) {
            if ($this->sandwich) {
                $this->childRows = $this->unfilteredRows;
                $this->childSelected = 0;
                $this->childTopRow = 0;
            } else {
                $this->rows = $this->unfilteredRows;
                $this->selected = 0;
                $this->topRow = 0;
            }
            $this->unfilteredRows = [];
        }
    }

    private function dispatchRaw(string $key): void
    {
        match ($key) {
            'c' => $this->switchToClassRanking(),
            'y' => $this->switchToTypeRanking(),
            'r' => $this->toggleSort(),
            'g' => $this->goToDefinition(),
            'a' => $this->startAddressJump(),
            'm' => $this->toggleBookmark(),
            "'" => $this->switchToBookmarks(),
            'i' => $this->showSubtreeInfo(),
            'x' => $this->showCycles(),
            default => null,
        };
    }

    private function toggleAllEdges(): void
    {
        $this->allEdges = !$this->allEdges;
        $this->refreshChildren();
    }

    private function toggleSort(): void
    {
        $this->sortMode = $this->sortMode === 'retained' ? 'link' : 'retained';
        $this->refreshChildren();
    }

    private function refreshChildren(): void
    {
        if ($this->sandwich) {
            $this->childRows = $this->model->getChildren($this->sandwichNodeId, $this->allEdges, $this->sortMode);
            $this->childSelected = 0;
            $this->childTopRow = 0;
        }
    }

    private function goToDefinition(): void
    {
        // Determine which node to look up — use the selected row in
        // the active pane, not just the sandwich focus.
        $nodeId = null;
        if ($this->sandwich) {
            if ($this->activePane === 'children' && isset($this->childRows[$this->childSelected])) {
                $nodeId = $this->childRows[$this->childSelected]['node_id'];
            } elseif ($this->activePane === 'parents' && isset($this->parentRows[$this->parentSelected])) {
                $nodeId = $this->parentRows[$this->parentSelected]['node_id'];
            } else {
                $nodeId = $this->sandwichNodeId;
            }
        } elseif (isset($this->rows[$this->selected])) {
            $nodeId = $this->rows[$this->selected]['node_id'];
        }
        if ($nodeId === null || $nodeId < 0) {
            return;
        }

        // Try function name from frame label ("Class::method:42" or "func:42")
        $frameLabel = $this->model->getFrameLabel($nodeId);
        if ($frameLabel !== null) {
            // Strip trailing :lineno
            $funcName = preg_replace('/:\d+$/', '', $frameLabel);
            if ($funcName !== null && $funcName !== '') {
                $defId = $this->model->findFunctionDef($funcName);
                if ($defId !== null) {
                    $this->enterSandwich($defId, "def: {$funcName}");
                    return;
                }
                // Try class part only for class definition lookup
                $classEnd = strrpos($funcName, '::');
                if ($classEnd !== false) {
                    $className = substr($funcName, 0, $classEnd);
                    $defId = $this->model->findClassDef($className);
                    if ($defId !== null) {
                        $this->enterSandwich($defId, "def: {$className}");
                        return;
                    }
                }
            }
        }

        // Try class name
        $class = $this->model->resolveClassPublic($nodeId);
        if ($class !== null) {
            $defId = $this->model->findClassDef($class);
            if ($defId !== null) {
                $this->enterSandwich($defId, "def: {$class}");
                return;
            }
        }
    }

    private function switchToTopRetained(): void
    {
        $this->clearFilter();
        $this->sandwich = false;
        $this->focusStack = [];
        $this->focusNodeId = null;
        $this->focusLabel = 'Top retained';
        $this->listMode = 'normal';
        $this->rows = $this->model->getTopRetained(10000);
        $this->selected = 0;
        $this->topRow = 0;
    }

    private function switchToRoots(): void
    {
        $this->clearFilter();
        $this->sandwich = false;
        $this->focusStack = [];
        $this->focusNodeId = null;
        $this->focusLabel = 'Roots';
        $this->listMode = 'normal';
        $this->rows = $this->model->getRootChildren();
        $this->selected = 0;
        $this->topRow = 0;
    }

    private function switchToClassRanking(): void
    {
        $this->sandwich = false;
        $this->focusStack = [];
        $this->focusLabel = 'Class ranking';
        $this->listMode = 'class_ranking';
        $ranking = $this->model->getClassRanking();
        $this->rows = [];
        foreach ($ranking as $r) {
            $this->rows[] = [
                'node_id' => -1,
                'retained' => $r['total_shallow'],
                'shallow' => $r['avg_shallow'],
                'label' => sprintf('%s (%s instances, avg %s)',
                    $r['class'],
                    number_format($r['count']),
                    SizeFormatter::format($r['avg_shallow']),
                ),
                'link_name' => number_format($r['count']),
                '_class' => $r['class'],
                '_count' => $r['count'],
            ];
        }
        $this->selected = 0;
        $this->topRow = 0;
    }

    private function switchToTypeRanking(): void
    {
        $this->sandwich = false;
        $this->focusStack = [];
        $this->focusLabel = 'Type ranking';
        $this->listMode = 'type_ranking';
        $ranking = $this->model->getTypeRanking();
        $this->rows = [];
        foreach ($ranking as $r) {
            $this->rows[] = [
                'node_id' => -1,
                'retained' => $r['total_shallow'],
                'shallow' => 0,
                'label' => sprintf('%s (%s nodes)',
                    $r['type'],
                    number_format($r['count']),
                ),
                '_type' => $r['type'],
                '_count' => $r['count'],
            ];
        }
        $this->selected = 0;
        $this->topRow = 0;
    }

    // ---- Rendering ----

    private function render(): void
    {
        $this->model->ensureLocationInfoLoaded();
        [$cols, $rows] = $this->term->size();

        $sidebarW = 0;
        $sidebarLines = [];
        if ($this->showSidebar && $cols > 80) {
            $sidebarW = min(40, (int)($cols * 0.3));
            if ($this->sandwich) {
                // Show detail for the selected row in the active pane
                if ($this->activePane === 'children' && isset($this->childRows[$this->childSelected])) {
                    $focusId = $this->childRows[$this->childSelected]['node_id'];
                } elseif ($this->activePane === 'parents' && isset($this->parentRows[$this->parentSelected])) {
                    $focusId = $this->parentRows[$this->parentSelected]['node_id'];
                } else {
                    $focusId = $this->sandwichNodeId;
                }
            } else {
                $focusId = $this->rows[$this->selected]['node_id'] ?? null;
            }
            if ($focusId !== null) {
                $allSidebarLines = $this->buildSidebarLines($focusId, $sidebarW, $rows + $this->sidebarScroll);
                // Apply scroll
                $sidebarLines = array_slice($allSidebarLines, $this->sidebarScroll);
                // Clamp scroll
                $maxScroll = max(0, count($allSidebarLines) - ($rows - 2));
                if ($this->sidebarScroll > $maxScroll) {
                    $this->sidebarScroll = $maxScroll;
                }
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

        // Sidebar: render using cursor positioning instead of string concat.
        // This avoids ANSI visible-width miscalculation entirely.
        $sidebarBuf = '';
        if ($sidebarW > 0 && $sidebarLines !== []) {
            $sepCol = $mainW + 1;
            for ($i = 1; $i < count($lines); $i++) {
                $row = $i + 1; // 1-based terminal row
                $sLine = $sidebarLines[$i - 1] ?? '';
                if (strlen($sLine) > $sidebarW - 1) {
                    $sLine = substr($sLine, 0, $sidebarW - 4) . '...';
                }
                $sidebarBuf .= "\e[{$row};{$sepCol}H\e[2m│\e[22m{$sLine}";
            }
        }

        if ($this->showHelp) {
            $this->renderHelpOverlay($lines, $cols, $rows);
        }

        // Prompt overlays on last line
        if ($this->addrPrompt) {
            $lastIdx = count($lines) - 1;
            $lines[$lastIdx] = "\e[1m@\e[0m" . $this->addrInput . "\e[5m▌\e[0m  (0x... addr, #N node ID)";
        } elseif ($this->filterPrompt) {
            $lastIdx = count($lines) - 1;
            $lines[$lastIdx] = "\e[1m/\e[0m" . $this->filterInput . "\e[5m▌\e[0m";
        } elseif ($this->filterPattern !== null) {
            $lastIdx = count($lines) - 1;
            $lines[$lastIdx] .= "  \e[33m[filter: {$this->filterPattern}]\e[0m";
        }

        $body = implode("\n", array_slice($lines, 1)) . $sidebarBuf;
        $header = $lines[0] ?? '';

        if ($body !== $this->lastRendered) {
            // Body changed: full clear then redraw (sidebar uses cursor
            // positioning, so \e[J alone can't clear stale sidebar content)
            $this->lastRendered = $body;
            $this->term->write("\e[2J\e[H" . $header . "\n" . $body);
        } else {
            // Body unchanged: only update header line (spinner)
            $this->term->write("\e[H" . $header . "\e[K");
        }
    }

    /**
     * Build sidebar lines showing node detail + path-to-root.
     * @return list<string>
     */
    private function buildSidebarLines(int $nodeId, int $width, int $totalRows): array
    {
        $lines = [];
        $detail = $this->model->nodeDetail($nodeId);
        $usable = $width - 2; // 1 space indent + 1 margin

        $wrap = function (string $s) use (&$lines, $usable): void {
            if ($usable < 4) {
                $lines[] = ' ' . $s;
                return;
            }
            $maxWrapLines = 3; // keep sidebar compact
            $wrapped = 0;
            while (strlen($s) > $usable && $wrapped < $maxWrapLines) {
                $lines[] = ' ' . substr($s, 0, $usable);
                $s = substr($s, $usable);
                $wrapped++;
            }
            if ($s !== '') {
                $lines[] = ' ' . $s;
            }
        };

        // Node detail
        $lines[] = "\e[1m Node detail:\e[0m";
        $wrap("type: {$detail['type']}");
        if ($detail['class'] !== null) {
            $wrap("class: {$detail['class']}");
        }
        $wrap("shallow: " . SizeFormatter::format($detail['shallow']));
        $wrap("retained: " . SizeFormatter::format($detail['retained']));
        if ($detail['address'] !== null) {
            $wrap("addr: 0x" . dechex($detail['address']));
        }
        if ($detail['refcount'] !== null) {
            $wrap("refcount: {$detail['refcount']}");
        }
        if ($detail['string_value'] !== null) {
            $val = RmemModel::sanitizeForTerminal($detail['string_value']);
            $wrap("val: \"{$val}\"");
        }
        foreach ($detail['attributes'] as $key => $val) {
            $wrap("{$key}: {$val}");
        }

        // SCC membership
        $sccId = $this->model->getNodeSccId($nodeId);
        if ($sccId !== null) {
            $sccProfile = $this->model->getSccProfile($sccId);
            if ($sccProfile !== null) {
                $lines[] = '';
                $sig = $sccProfile['signature'] ?? '';
                if (strlen($sig) > $usable - 10) {
                    $sig = substr($sig, 0, $usable - 13) . '...';
                }
                $wrap("\e[33mSCC#{$sccId}: {$sccProfile['node_count']} nodes\e[0m");
                if ($sig !== '') {
                    $wrap($sig);
                }
            }
        }

        $lines[] = '';
        $lines[] = "\e[1m Path to root:\e[0m";

        $path = $this->model->pathToRoot($nodeId);
        foreach ($path as $i => $step) {
            $indent = str_repeat(' ', min($i, 8));
            $link = $step['link_name'];
            $label = $step['label'];
            $text = "{$indent}[{$link}] {$label}";
            $wrap($text);
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
        $statusParts = [
            number_format(count($this->rows)) . ' nodes',
            SizeFormatter::format($this->model->nodeSizesSum()) . ' total',
            'depth ' . count($this->focusStack),
        ];
        if ($this->socketPath !== null) {
            $statusParts[] = 'sock:' . $this->socketPath;
        }
        $lines[] = ' ' . implode(' | ', $statusParts);
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
        // Use live label (may have class from lazy-loaded locations)
        // Ranking rows have node_id=-1; use pre-computed label.
        $label = $link . ($row['node_id'] >= 0
            ? $this->model->nodeLabel($row['node_id'])
            : $row['label']);
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
        if ($this->listMode === 'class_ranking') {
            return sprintf(
                "\e[1m  %12s %12s  %-*s\e[0m",
                'Total',
                'Avg Size',
                max(10, $cols - 30),
                'Count × Class',
            );
        }
        if ($this->listMode === 'type_ranking') {
            return sprintf(
                "\e[1m  %12s %12s  %-*s\e[0m",
                'Total',
                '',
                max(10, $cols - 30),
                'Type (count)',
            );
        }
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
        $edgeMode = $this->allEdges ? ' all-edges' : '';
        $sortInfo = $this->sortMode === 'link' ? ' by-name' : '';
        $paneInfo = $this->activePane === 'sidebar' ? ' sidebar' : '';
        $mode = $this->sandwich ? "[sandwich{$edgeMode}{$sortInfo}{$paneInfo}]" : "[list{$edgeMode}{$sortInfo}{$paneInfo}]";

        $spinner = '';
        if ($this->sccBuilderPid !== null) {
            $spinner .= ' ' . self::$SPINNER[$this->spinnerFrame] . ' SCC';
        }
        if ($this->queryChildPid !== null) {
            $spinner .= ' ' . self::$SPINNER[$this->spinnerFrame] . ' serving';
        }

        $info = sprintf(
            "%s %s nodes, %s edges%s ",
            $mode,
            number_format($this->model->nodeCount),
            number_format($this->model->edgeCount),
            $spinner,
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
            ? " ↑↓:sel Tab:pane Enter:focus Bksp:back g:def r:sort n:edges m:mark ':marks o:side s:top t:roots ?:help q:quit"
            : " ↑↓:sel Enter:drill Bksp:back /:filt r:sort c:class y:type a:addr g:def m:mark ':marks o:side s:top t:roots q:quit";
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
            '    c               Class ranking',
            '    y               Type ranking',
            '    /               Filter current list (Enter to apply)',
            '    a               Jump to address (0x...) or node (#N)',
            '    g               Go to definition (func/class table)',
            '    i               Subtree info (type/class breakdown)',
            '    x               Cycle list (SCCs from sidecar)',
            '    m               Toggle bookmark on selected node',
            "    '               Show bookmarks list",
            '    r               Toggle sort: retained / link name',
            '    n               Toggle tree/all edges',
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
