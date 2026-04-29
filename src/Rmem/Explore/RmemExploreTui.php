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
use Reli\Rbt\Explore\MouseEvent;
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
    private bool $showOverview = false;
    /** @var list<string> plain-text heap/memory overview lines (matches inspector:memory:report overview) */
    private array $overviewLines = [];
    private bool $showSidebar = true;

    /**
     * Whether to paint the full-width source-banner row just above the
     * footer. The banner duplicates the sidebar's source / defined /
     * held-by line at full terminal width so terminals that don't
     * emit motion events (PhpStorm JediTerm) — and therefore can't
     * drive the sidebar's hover overlay — still see a contiguous
     * `file:line` token their pattern matcher can latch onto. Toggled
     * with `b`.
     */
    private bool $showSourceBanner = true;

    /**
     * Node id the next render() should source the banner text from.
     * Computed at the top of render() so renderList / renderSandwich
     * can pull it without re-running the cursor-pane dispatch.
     */
    private ?int $bannerFocusId = null;

    /**
     * How many banner rows the last render emitted — 0 when the
     * banner is off or the focused node has no source location;
     * otherwise one row per `source_locations` kind (self /
     * defined_at / held_by, up to 3). hitTestMouseRow() reads
     * this to offset the sandwich pane boundaries correctly,
     * since every banner row steals a row from the bottom that
     * the unfixed formula would count as the last child row.
     */
    private int $lastRenderBannerRowCount = 0;

    /**
     * Exact 1-based terminal row range that holds the last render's
     * scrollable body content (list rows / sandwich children rows).
     * Mouse clicks below `$lastRenderBodyEndRow` are inside the
     * non-interactive bottom strip (banner + footer + status) and
     * must be suppressed before any pane hit-test or sidebar
     * lookup runs — relying on `lastRenderBannerRowCount` alone
     * isn't enough, because a focus-change between render and
     * click can leave the cached count out of sync with what's
     * actually on screen, and the click then falls through to
     * children rows that aren't where the user thinks they are.
     */
    private int $lastRenderBodyEndRow = 0;

    /**
     * Last render's children-pane visible terminal-row window in
     * sandwich view. -1 when sandwich isn't active. Stored so the
     * sandwich hit-test answers strictly against what was on screen
     * at render time, not whatever halfH / banner derivation
     * happens to compute now.
     */
    private int $lastRenderChildrenStart = -1;
    private int $lastRenderChildrenEnd = -1;
    private int $lastRenderParentsStart = -1;
    private int $lastRenderParentsEnd = -1;
    private int $lastRenderListStart = -1;
    private int $lastRenderListEnd = -1;
    private ?string $filterPattern = null;
    private string $filterInput = '';
    private bool $filterPrompt = false;
    private bool $addrPrompt = false;
    private string $addrInput = '';
    private bool $searchPrompt = false;
    private string $searchInput = '';
    /** @var list<array{node_id: int, retained: int, shallow: int, label: string, link_name?: string}> */
    private array $unfilteredRows = [];
    private bool $allEdges = true;
    /** 'retained' | 'link' */
    private string $sortMode = 'retained';

    /** @var 'normal'|'class_ranking'|'type_ranking'|'class_instances'|'type_instances'|'scc_members' */
    private string $listMode = 'normal';
    private string $listModeParam = '';

    private int $spinnerFrame = 0;
    private string $lastRendered = '';
    private static array $SPINNER = ['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏'];
    private ?int $queryChildPid = null;
    private ?int $sccBuilderPid = null;
    /** @var array<int, string> node_id => label */
    private array $bookmarks = [];
    /** @var resource|null pipe read-end for incoming ui.* requests from query child */
    private mixed $uiRequestRead = null;
    /** @var resource|null pipe write-end for sending ui.* responses to query child */
    private mixed $uiResponseWrite = null;
    private int $uiRevision = 0;

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

    /** @param list<string> $lines */
    public function setOverviewLines(array $lines): void
    {
        $this->overviewLines = $lines;
    }

    /**
     * @param resource $requestRead  pipe read-end (child→parent ui.* requests)
     * @param resource $responseWrite pipe write-end (parent→child ui.* responses)
     */
    public function setUiPipes(mixed $requestRead, mixed $responseWrite): void
    {
        $this->uiRequestRead = $requestRead;
        $this->uiResponseWrite = $responseWrite;
    }

    /** @var resource|null */
    private mixed $focusEventPipe = null;

    /** @var resource|null */
    private mixed $navigateEventPipe = null;

    private string $navigateBuf = '';

    /** True while an externally-driven navigate is in progress. */
    private bool $suppressFocusEmit = false;

    /** When on, every cursor move in the TUI also broadcasts a
     *  focus event so the browser (and any other SSE subscriber)
     *  follows the cursor live, without the user having to press
     *  Enter. Toggled with 'F'. */
    private bool $followMode = false;

    /** @var array<int,int> sidebar_line_index → node_id, populated
     *  per render by buildSidebarLines so mouse clicks on a path-to-
     *  root step can navigate to it. */
    private array $sidebarPathMap = [];

    /** Leftmost 1-based terminal column occupied by the sidebar at
     *  the last render (0 if the sidebar is hidden). */
    private int $sidebarStartCol = 0;

    /**
     * Write end of a pipe to a sidecar process (the --http-bridge child).
     * Whenever the sandwich focus changes, a JSON line
     * {"node_id": N} is emitted so the bridge can broadcast to browsers.
     *
     * @param resource $writeEnd
     */
    public function setFocusEventPipe(mixed $writeEnd): void
    {
        $this->focusEventPipe = $writeEnd;
    }

    /**
     * Read end of a pipe from the bridge child. The bridge writes
     * {"node_id": N}\n whenever a browser / AI hits /api/navigate so
     * the TUI can jump to the same node.
     *
     * @param resource $readEnd
     */
    public function setNavigateEventPipe(mixed $readEnd): void
    {
        $this->navigateEventPipe = $readEnd;
    }

    private function emitFocusEvent(int $nodeId): void
    {
        if ($this->focusEventPipe === null || $this->suppressFocusEmit) {
            return;
        }
        // Sentinel (id < 0) is a synthetic "(roots)" anchor — no real
        // node on the other end, so there is nothing for the browser
        // or MCP clients to highlight.
        if ($nodeId < 0) {
            return;
        }
        $line = (string)json_encode(['node_id' => $nodeId], JSON_UNESCAPED_UNICODE) . "\n";
        @fwrite($this->focusEventPipe, $line);
    }

    /**
     * Emit a focus event for whichever node is currently under the
     * cursor when follow-mode is on. Called after every selection
     * change (arrow / page / home / end / mouse click / tab).
     */
    private function broadcastIfFollow(): void
    {
        if (!$this->followMode) {
            return;
        }
        $nodeId = $this->currentCursorNodeId();
        if ($nodeId !== null) {
            $this->emitFocusEvent($nodeId);
        }
    }

    private function currentCursorNodeId(): ?int
    {
        if ($this->sandwich) {
            if ($this->activePane === 'parents') {
                return $this->parentRows[$this->parentSelected]['node_id'] ?? null;
            }
            if ($this->activePane === 'children') {
                return $this->childRows[$this->childSelected]['node_id'] ?? null;
            }
            return $this->sandwichNodeId;
        }
        return $this->rows[$this->selected]['node_id'] ?? null;
    }

    private function handleIncomingNavigate(int $nodeId): void
    {
        if ($nodeId < 0) {
            return;
        }
        // Suppress the outbound focus emit so we don't echo right back
        // to the bridge (which would re-broadcast the same id in a loop).
        $this->suppressFocusEmit = true;
        try {
            $label = $this->model->nodeLabel($nodeId);
            $this->enterSandwich($nodeId, $label);
        } finally {
            $this->suppressFocusEmit = false;
        }
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
        $hasUiPipe = $this->uiRequestRead !== null || $this->navigateEventPipe !== null;

        $this->term->enter();
        try {
            while ($this->running) {
                $this->render();

                if ($hasUiPipe) {
                    // Combined stream_select on tty + ui pipe
                    $key = $this->pollKeyOrUiPipe();
                    $this->spinnerFrame = ($this->spinnerFrame + 1) % 10;
                    $this->checkChildStatus();
                } elseif ($useTimeout) {
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

    /**
     * Wait for either a key on tty or a ui.* request on the pipe.
     * Handles any pending ui requests and returns the key (or null on timeout).
     */
    private function pollKeyOrUiPipe(): ?string
    {
        $streams = [];
        $ttyIn = $this->term->getInputStream();
        if ($ttyIn !== null) {
            $streams[] = $ttyIn;
        }
        if ($this->uiRequestRead !== null) {
            $streams[] = $this->uiRequestRead;
        }
        if ($this->navigateEventPipe !== null) {
            $streams[] = $this->navigateEventPipe;
        }
        if ($streams === []) {
            return null;
        }

        $read = $streams;
        $write = null;
        $except = null;
        $changed = @stream_select($read, $write, $except, 0, 100_000); // 100ms
        if ($changed === false || $changed === 0) {
            return null;
        }

        // Process ui pipe if readable
        if ($this->uiRequestRead !== null && in_array($this->uiRequestRead, $read, true)) {
            $this->processUiRequest();
        }

        // Drain any pending navigate events from the bridge child
        if ($this->navigateEventPipe !== null && in_array($this->navigateEventPipe, $read, true)) {
            $this->drainNavigatePipe();
        }

        // Process tty if readable
        if ($ttyIn !== null && in_array($ttyIn, $read, true)) {
            return $this->term->readKey();
        }

        return null;
    }

    private function drainNavigatePipe(): void
    {
        if ($this->navigateEventPipe === null) {
            return;
        }
        $chunk = @fread($this->navigateEventPipe, 4096);
        if ($chunk === false || $chunk === '') {
            return;
        }
        $this->navigateBuf .= $chunk;
        while (($nl = strpos($this->navigateBuf, "\n")) !== false) {
            $line = substr($this->navigateBuf, 0, $nl);
            $this->navigateBuf = substr($this->navigateBuf, $nl + 1);
            /** @var mixed $decoded */
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['node_id']) && is_int($decoded['node_id'])) {
                $this->handleIncomingNavigate($decoded['node_id']);
            }
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

    /**
     * Read and handle a single ui.* request from the pipe.
     */
    private function processUiRequest(): void
    {
        if ($this->uiRequestRead === null || $this->uiResponseWrite === null) {
            return;
        }

        $line = fgets($this->uiRequestRead);
        if ($line === false || $line === '') {
            // Pipe closed — child exited
            $this->uiRequestRead = null;
            return;
        }

        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded)) {
            $this->sendUiResponse(['ok' => false, 'error' => 'Invalid JSON']);
            return;
        }
        /** @var array<string, mixed> $request */
        $request = $decoded;

        $action = (string)($request['action'] ?? '');

        // Optimistic revision check
        if (isset($request['if_revision'])) {
            $expected = (int)$request['if_revision'];
            if ($expected !== $this->uiRevision) {
                $this->sendUiResponse([
                    'ok' => false,
                    'error' => 'Stale revision',
                    'error_code' => 'stale_revision',
                    'ui_revision' => $this->uiRevision,
                ]);
                return;
            }
        }

        $response = match ($action) {
            'ui.navigate_sandwich' => $this->handleUiNavigateSandwich($request),
            'ui.navigate_roots' => $this->handleUiNavigateRoots(),
            'ui.navigate_back' => $this->handleUiNavigateBack(),
            'ui.navigate_top_retained' => $this->handleUiNavigateView('top_retained'),
            'ui.navigate_class_ranking' => $this->handleUiNavigateView('class_ranking'),
            'ui.navigate_type_ranking' => $this->handleUiNavigateView('type_ranking'),
            'ui.get_current_focus' => $this->handleUiGetCurrentFocus(),
            'ui.get_current_selection' => $this->handleUiGetCurrentSelection(),
            default => ['ok' => false, 'error' => "Unknown ui action: {$action}"],
        };

        $response['ui_revision'] = $this->uiRevision;
        $this->sendUiResponse($response);
    }

    /** @param array<string, mixed> $response */
    private function sendUiResponse(array $response): void
    {
        if ($this->uiResponseWrite === null) {
            return;
        }
        $payload = (string)json_encode($response, JSON_UNESCAPED_UNICODE) . "\n";
        @fwrite($this->uiResponseWrite, $payload);
        @fflush($this->uiResponseWrite);
    }

    /** @param array<string, mixed> $req */
    private function handleUiNavigateSandwich(array $req): array
    {
        $nodeId = (int)($req['node_id'] ?? -1);
        if ($nodeId < 0) {
            return ['ok' => false, 'error' => 'Missing or invalid node_id'];
        }
        $label = $this->model->nodeLabel($nodeId);
        $this->enterSandwich($nodeId, $label);
        $this->uiRevision++;
        return ['ok' => true, 'data' => ['node_id' => $nodeId, 'label' => $label, 'mode' => 'sandwich']];
    }

    private function handleUiNavigateRoots(): array
    {
        $this->sandwich = false;
        $this->clearFilter();
        $this->focusStack = [];
        $this->focusLabel = 'Root branches';
        $this->listMode = 'normal';
        $this->rows = $this->model->getRootChildren();
        $this->selected = 0;
        $this->topRow = 0;
        $this->uiRevision++;
        return ['ok' => true, 'data' => ['mode' => 'list', 'view' => 'roots']];
    }

    private function handleUiNavigateView(string $view): array
    {
        match ($view) {
            'top_retained' => $this->switchToTopRetained(),
            'class_ranking' => $this->switchToClassRanking(),
            'type_ranking' => $this->switchToTypeRanking(),
            default => null,
        };
        $this->uiRevision++;
        return ['ok' => true, 'data' => ['mode' => 'list', 'view' => $view]];
    }

    private function handleUiNavigateBack(): array
    {
        $canGoBack = $this->sandwich
            || $this->focusStack !== []
            || $this->listMode === 'class_instances'
            || $this->listMode === 'type_instances'
            || $this->listMode === 'scc_members';
        if (!$canGoBack) {
            return ['ok' => false, 'error' => 'Already at root, cannot go back'];
        }
        $this->back();
        $this->uiRevision++;
        return ['ok' => true, 'data' => ['mode' => $this->sandwich ? 'sandwich' : 'list']];
    }

    private function handleUiGetCurrentFocus(): array
    {
        if ($this->sandwich) {
            return [
                'ok' => true,
                'data' => [
                    'mode' => 'sandwich',
                    'node_id' => $this->sandwichNodeId,
                    'label' => $this->model->nodeLabel($this->sandwichNodeId),
                ],
            ];
        }
        return [
            'ok' => true,
            'data' => [
                'mode' => 'list',
                'view' => $this->listMode,
                'label' => $this->focusLabel,
            ],
        ];
    }

    private function handleUiGetCurrentSelection(): array
    {
        if ($this->rows === [] || !isset($this->rows[$this->selected])) {
            return ['ok' => true, 'data' => null, 'message' => 'No selection'];
        }
        $row = $this->rows[$this->selected];
        return [
            'ok' => true,
            'data' => [
                'node_id' => $row['node_id'],
                'label' => $row['label'] ?? '',
                'retained' => $row['retained'] ?? 0,
                'shallow' => $row['shallow'] ?? 0,
                'index' => $this->selected,
                'total_rows' => count($this->rows),
                'mode' => $this->sandwich ? 'sandwich' : 'list',
            ],
        ];
    }

    private function dispatch(string $key): void
    {
        // Route SGR mouse escape sequences to the mouse dispatcher before
        // the keymap gets a chance to mis-handle them (and before the
        // prompt input handlers swallow the "\e[" prefix). Prompts ignore
        // the click; everything else treats a left-click on a list row
        // as "move selection here", a double-click as Enter, and the
        // scroll wheel as arrow up/down.
        $mouse = MouseEvent::tryParse($key);
        if ($mouse !== null) {
            if (
                !$this->filterPrompt
                && !$this->addrPrompt
                && !$this->searchPrompt
                && !$this->showHelp
                && !$this->showOverview
            ) {
                $this->handleMouseEvent($mouse);
            }
            return;
        }

        $action = $this->keymap->resolve($key);

        if ($this->showHelp) {
            $this->showHelp = false;
            return;
        }
        if ($this->showOverview) {
            $this->showOverview = false;
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
        if ($this->searchPrompt) {
            $this->handleSearchInput($key);
            return;
        }

        // Live-follow toggle: 'f' broadcasts every cursor move to
        // browsers / MCP so they track the TUI cursor in real time,
        // without the user having to Enter into each row. (Uppercase
        // F is the global-search shortcut, handled in dispatchRaw.)
        if ($key === 'f') {
            $this->followMode = !$this->followMode;
            if ($this->followMode) {
                $this->broadcastIfFollow();
            }
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
            Keymap::ACTION_VIEW_OVERVIEW => $this->showOverview = $this->overviewLines !== [],
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
        $this->broadcastIfFollow();
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
        $this->broadcastIfFollow();
    }

    private const MOUSE_DOUBLE_CLICK_MS = 400;
    private float $lastClickTime = 0.0;
    private int $lastClickRow = -1;
    private int $lastClickCol = -1;

    /**
     * Mouse handling: left-click on a data row moves the cursor there;
     * double-click presses Enter (drill down); scroll wheel nudges the
     * selection of the pane under the cursor. Sandwich layout is read
     * out of the terminal rows returned by the current viewport so the
     * pane hit-test stays in sync with renderSandwich().
     */
    private function handleMouseEvent(MouseEvent $mouse): void
    {
        if (!$mouse->press || $mouse->drag) {
            return;
        }
        $isScroll = $mouse->button === MouseEvent::SCROLL_UP
            || $mouse->button === MouseEvent::SCROLL_DOWN;
        if (!$isScroll && $mouse->button !== MouseEvent::BUTTON_LEFT) {
            return;
        }

        // Bottom-reserved rows (source banner + footer + status) are
        // visually non-interactive for the TUI. Suppress *non-scroll*
        // clicks that land there so the click stays available to the
        // terminal — particularly the file:line pattern matcher in
        // PhpStorm / VS Code etc., which fires on Shift+Click. Without
        // this check, a click on the banner's right-hand half (which
        // spans the full terminal width) would fall through to the
        // path-to-root sidebar handler below and navigate the cursor
        // to whichever ancestor happens to share that row.
        //
        // Use the row that the *last actual render* committed, not a
        // value freshly recomputed from `lastRenderBannerRowCount`,
        // so a focus-change happening between render and click can't
        // shift the threshold out from under the user.
        if (!$isScroll && $this->lastRenderBodyEndRow > 0 && $mouse->row > $this->lastRenderBodyEndRow) {
            return;
        }

        // Path-to-root row in the sidebar: clicking a step jumps
        // sandwich view to that ancestor. Tested before pane hit so
        // scroll events still work inside the sidebar area.
        if ($this->sidebarStartCol > 0 && $mouse->col >= $this->sidebarStartCol && !$isScroll) {
            $sidebarIdx = $mouse->row - 2; // rendered at row = idx + 2
            if (isset($this->sidebarPathMap[$sidebarIdx])) {
                $nodeId = $this->sidebarPathMap[$sidebarIdx];
                if ($nodeId >= 0) {
                    $this->enterSandwich($nodeId, $this->model->nodeLabel($nodeId));
                }
                return;
            }
        }

        [$hitPane, $hitIdx] = $this->hitTestMouseRow($mouse->row);

        if ($isScroll) {
            $delta = $mouse->button === MouseEvent::SCROLL_UP ? -3 : 3;
            // If the pointer is over a specific pane, scroll that pane
            // by temporarily focusing it so moveSelection acts on the
            // right collection. Otherwise fall back to active pane.
            $prevPane = $this->activePane;
            if ($hitPane !== null) {
                $this->activePane = $hitPane;
            }
            $this->moveSelection($delta);
            $this->activePane = $prevPane;
            return;
        }

        // Left click outside any data area — ignore.
        if ($hitPane === null || $hitIdx === null) {
            return;
        }

        // Move the clicked pane's cursor and make it active.
        $this->activePane = $hitPane;
        if ($hitPane === 'parents') {
            $this->parentSelected = $hitIdx;
        } elseif ($hitPane === 'children') {
            $this->childSelected = $hitIdx;
        } else {
            $this->selected = $hitIdx;
        }
        $this->broadcastIfFollow();

        $now = microtime(true);
        $isDouble = ($now - $this->lastClickTime) < ((float)self::MOUSE_DOUBLE_CLICK_MS / 1000.0)
            && $this->lastClickRow === $mouse->row
            && $this->lastClickCol === $mouse->col;
        $this->lastClickTime = $now;
        $this->lastClickRow = $mouse->row;
        $this->lastClickCol = $mouse->col;
        if ($isDouble) {
            $this->lastClickTime = 0.0; // consume, prevent triple-click repeat
            $this->enter();
        }
    }

    /**
     * Map a 1-based terminal row onto (pane, dataIndex). Returns
     * [null, null] when the row is outside every pane's data area.
     *
     * @return array{0:?string,1:?int}
     */
    private function hitTestMouseRow(int $row): array
    {
        if (!$this->sandwich) {
            // Use the row range the *last actual render* committed.
            // Re-deriving from cached values like
            // `lastRenderBannerRowCount` is racy: a focus change
            // happening between render and click can leave the cached
            // count out of sync with what's actually on screen, and
            // the user's click then maps to a non-visible list index.
            if (
                $this->lastRenderListStart < 0
                || $row < $this->lastRenderListStart
                || $row > $this->lastRenderListEnd
            ) {
                return [null, null];
            }
            // List layout: header (1) + breadcrumb (2) + column header (3)
            // + separator (4), data rows start on 5 (lastRenderListStart).
            $idx = $this->topRow + ($row - $this->lastRenderListStart);
            if ($idx < 0 || $idx >= count($this->rows)) {
                return [null, null];
            }
            return ['list', $idx];
        }

        // Sandwich layout. Use the row ranges the *last actual
        // render* committed instead of re-deriving halfH /
        // childrenStart from cached values; otherwise a focus change
        // happening between render and click can leave the cached
        // banner-row count out of sync with the screen and route a
        // click to a non-visible child row at a row index that
        // happens to fall inside the dataset.
        if (
            $this->lastRenderParentsStart > 0
            && $row >= $this->lastRenderParentsStart
            && $row <= $this->lastRenderParentsEnd
        ) {
            $idx = $this->parentTopRow + ($row - $this->lastRenderParentsStart);
            if ($idx < 0 || $idx >= count($this->parentRows)) {
                return [null, null];
            }
            return ['parents', $idx];
        }
        if (
            $this->lastRenderChildrenStart > 0
            && $row >= $this->lastRenderChildrenStart
            && $row <= $this->lastRenderChildrenEnd
        ) {
            $idx = $this->childTopRow + ($row - $this->lastRenderChildrenStart);
            if ($idx < 0 || $idx >= count($this->childRows)) {
                return [null, null];
            }
            return ['children', $idx];
        }
        return [null, null];
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
        $this->emitFocusEvent($nodeId);
        $this->uiRevision++;
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
        $this->uiRevision++;
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
        $this->emitFocusEvent($nodeId);
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
        $this->broadcastIfFollow();
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

    /**
     * Saved state before SCC member view, restored when leaving the
     * `scc_members` mode. Mirrors the corresponding properties' shapes
     * so destructuring at the restore site narrows correctly.
     *
     * @var array{
     *     sandwich: bool,
     *     sandwichNodeId: int,
     *     sandwichLabel: string,
     *     sandwichHistory: list<array{node_id: int, label: string, pane: string}>,
     *     rows: list<array{node_id: int, retained: int, shallow: int, label: string, link_name?: string}>,
     *     selected: int,
     *     topRow: int,
     *     focusLabel: string,
     *     listMode: 'normal'|'class_ranking'|'type_ranking'|'class_instances'|'type_instances'|'scc_members',
     *     parentRows: list<array{node_id: int, retained: int, shallow: int, label: string, link_name?: string}>,
     *     childRows: list<array{node_id: int, retained: int, shallow: int, label: string, link_name?: string}>,
     *     activePane: string,
     * }|null
     */
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
        $memberSet = array_flip($profile['nodes']);

        // Build cycle path: for each member, find which other members it references
        $this->sandwich = false;
        $this->clearFilter();
        $this->focusStack = [];
        $this->focusLabel = "SCC#{$sccId} members ({$profile['node_count']} nodes)";
        $this->listMode = 'scc_members';
        $this->rows = [];

        foreach ($profile['nodes'] as $nid) {
            // Find edges within the SCC (all edges, not just tree)
            $targets = [];
            foreach ($this->model->getSubstrate()->getAllChildren($nid) as $childId) {
                if (isset($memberSet[$childId]) && $childId !== $nid) {
                    $link = $this->model->getSubstrate()->getTreeLinkName($childId) ?? '?';
                    $childLabel = $this->model->nodeLabel($childId);
                    $targets[] = "→ [{$link}] {$childLabel}";
                }
            }
            $arrow = $targets !== [] ? implode(', ', $targets) : '';
            if (strlen($arrow) > 60) {
                $arrow = substr($arrow, 0, 57) . '...';
            }

            $this->rows[] = [
                'node_id' => $nid,
                'retained' => $this->model->subtreeSize($nid),
                'shallow' => $this->model->nodeSize($nid),
                'label' => $this->model->nodeLabel($nid),
                'link_name' => $arrow !== '' ? $arrow : null,
            ];
        }

        // Sort by position in cycle (keep original order, which is Tarjan discovery order)
        // Don't sort by retained — cycle path order is more informative
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

    private function startGlobalSearch(): void
    {
        $this->searchPrompt = true;
        $this->searchInput = '';
    }

    private function handleSearchInput(string $key): void
    {
        if ($key === "\n" || $key === "\r") {
            $this->searchPrompt = false;
            $pattern = trim($this->searchInput);
            if ($pattern === '') {
                return;
            }
            $results = $this->model->globalSearch($pattern);
            $this->sandwich = false;
            $this->clearFilter();
            $this->focusStack = [];
            $this->focusLabel = "Search: \"{$pattern}\" (" . count($results) . " results)";
            $this->listMode = 'normal';
            $this->rows = $results;
            $this->selected = 0;
            $this->topRow = 0;
        } elseif ($key === "\e" || $key === "\x03") {
            $this->searchPrompt = false;
        } elseif ($key === "\x7f" || $key === "\x08") {
            $this->searchInput = substr($this->searchInput, 0, -1);
        } else {
            $c = ord($key);
            if ($c >= 32 && $c < 127) {
                $this->searchInput .= $key;
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
            'F' => $this->startGlobalSearch(),
            'm' => $this->toggleBookmark(),
            "'" => $this->switchToBookmarks(),
            'i' => $this->showSubtreeInfo(),
            'x' => $this->showCycles(),
            'b' => $this->showSourceBanner = !$this->showSourceBanner,
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
                'label' => sprintf(
                    '%s (%s instances, avg %s)',
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
                'label' => sprintf(
                    '%s (%s nodes)',
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

    /**
     * Maximum number of source-location kinds the resolver can
     * return — `self`, `defined_at`, `held_by`. The renderer treats
     * this as a constant *upper bound* when computing the focus
     * bar's y-position so the bar doesn't jump as the cursor walks
     * through nodes with different actual kind counts; the children
     * pane below it then absorbs whatever the actual banner doesn't
     * use, so there's no padding gap visible above the banner.
     */
    private const SOURCE_BANNER_MAX_ROWS = 3;

    /**
     * Build the full-width source-banner rows for $nodeId. Returns
     * up to {@see self::SOURCE_BANNER_MAX_ROWS} actual reverse-video
     * rows (no padding) — one per `source_locations` kind. Empty
     * when the banner is toggled off, the node has no source, or
     * the terminal is too narrow.
     *
     * Each kind (self / defined_at / held_by) gets its own row —
     * mirrors the sidebar so a node that has both `defined` and
     * `held by` doesn't lose half of its navigation hints.
     * Terminals' file:line pattern matchers only latch onto tokens
     * sitting on one line, so we intentionally don't fold them
     * together.
     *
     * @return list<string>
     */
    private function renderSourceBannerRows(?int $nodeId, int $cols): array
    {
        if (!$this->showSourceBanner || $nodeId === null) {
            return [];
        }
        $maxWidth = $cols - 2;
        if ($maxWidth < 8) {
            return [];
        }
        /** @var list<array{kind: string, filename: string, line: ?int, line_start: ?int, line_end: ?int, formatted: string}> $locs */
        $locs = $this->model->nodeDetail($nodeId)['source_locations'];
        $rows = [];
        foreach ($locs as $loc) {
            $label = match ($loc['kind']) {
                'self' => 'source',
                'defined_at' => 'defined',
                'held_by' => 'held by',
                default => $loc['kind'],
            };
            $text = $label . ': ' . $loc['formatted'];
            if (strlen($text) > $maxWidth) {
                $text = substr($text, 0, $maxWidth - 1) . '…';
            }
            // Reverse video keeps the banner visually distinct from
            // the status / footer rows without burning a color that
            // might clash with the user's terminal theme.
            $rows[] = "\e[7m " . str_pad($text, $cols - 1) . "\e[27m";
            if (count($rows) >= self::SOURCE_BANNER_MAX_ROWS) {
                break;
            }
        }
        return $rows;
    }

    /**
     * Worst-case rows the source-location banner can ever steal
     * from the body, regardless of what the currently focused node
     * carries. Used by the sandwich split so the focus bar's
     * y-position stays stable as the cursor walks through nodes —
     * children pane absorbs the slack at runtime.
     */
    private function maxBannerReserve(): int
    {
        return $this->showSourceBanner ? self::SOURCE_BANNER_MAX_ROWS : 0;
    }

    private function render(): void
    {
        $this->model->ensureLocationInfoLoaded();
        [$cols, $rows] = $this->term->size();

        // The sidebar and the source-banner both want the node id the
        // cursor is currently sitting on, regardless of whether the
        // user has drilled into it — mirrors the detail-pane policy.
        $focusId = $this->currentCursorNodeId();

        $sidebarW = 0;
        $sidebarLines = [];
        if ($this->showSidebar && $cols > 80) {
            $sidebarW = min(40, (int)($cols * 0.3));
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
        $this->bannerFocusId = $focusId;

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
        // Record the sidebar's left edge for the mouse hit-test; 0 means
        // the sidebar is not currently visible.
        $this->sidebarStartCol = ($sidebarW > 0 && $sidebarLines !== []) ? ($mainW + 1) : 0;
        if ($sidebarW > 0 && $sidebarLines !== []) {
            $sepCol = $mainW + 1;
            for ($i = 1; $i < count($lines); $i++) {
                $row = $i + 1; // 1-based terminal row
                $sLine = $sidebarLines[$i - 1] ?? '';
                // Sidebar lines may carry their own ANSI escapes
                // (e.g. the underlined-cyan styling on Path-to-root
                // entries). A blind byte-truncate cuts those mid-CSI
                // and leaks the unterminated escape onto the screen
                // — terminals like PhpStorm's JediTerm parse the
                // partial sequence + the next iteration's cursor
                // positioning escape together and either eat their
                // ESC or render the parameters as literal text,
                // which then bleeds into / overwrites neighbouring
                // panes. truncateToVisibleWidth() preserves CSI
                // sequences intact and resets attributes after the
                // suffix so the next sidebar / main-pane row
                // starts from a clean state.
                $sLine = self::truncateToVisibleWidth($sLine, $sidebarW - 1);
                $sidebarBuf .= "\e[{$row};{$sepCol}H\e[2m│\e[22m{$sLine}";
            }
        }

        if ($this->showHelp) {
            $this->renderHelpOverlay($lines, $cols, $rows);
        } elseif ($this->showOverview) {
            $this->renderOverviewOverlay($lines, $cols, $rows);
        }

        // Prompt overlays on last line
        if ($this->addrPrompt) {
            $lastIdx = count($lines) - 1;
            $lines[$lastIdx] = "\e[1m@\e[0m" . $this->addrInput . "\e[5m▌\e[0m  (0x... addr, #N node ID)";
        } elseif ($this->searchPrompt) {
            $lastIdx = count($lines) - 1;
            $lines[$lastIdx] = "\e[1mF\e[0m" . $this->searchInput . "\e[5m▌\e[0m  (search labels, classes, strings)";
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
        $this->sidebarPathMap = [];
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
        $wrap("node_id: #{$nodeId}");
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
            // Cap the sanitize input: $wrap() shows at most 3 sidebar
            // rows, so anything past that is discarded anyway. Without
            // the cap a multi-MB string_value would drag two preg_replace
            // scans over megabytes on every redraw and turn keypresses
            // into a visible freeze.
            $valMax = max(120, $usable * 3 + 32);
            $val = RmemModel::sanitizeForTerminal($detail['string_value'], $valMax);
            $wrap("val: \"{$val}\"");
        }
        foreach ($detail['source_locations'] as $loc) {
            $label = match ($loc['kind']) {
                'self' => 'source',
                'defined_at' => 'defined',
                'held_by' => 'held by',
                default => $loc['kind'],
            };
            $wrap("{$label}: {$loc['formatted']}");
        }
        foreach ($detail['attributes'] as $key => $val) {
            // filename / line_start / line_end / lineno are already
            // rendered via source_locations; class_name is surfaced in
            // the class: line. Suppress the raw entries so the detail
            // pane stays scannable.
            if (
                $key === 'filename' || $key === 'line_start' || $key === 'line_end'
                || $key === 'lineno' || $key === 'class_name'
            ) {
                continue;
            }
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
            // Record the sidebar line indices this step will occupy so a
            // mouse click on any of its wrapped rows can jump to it, and
            // paint those rows underlined cyan to cue clickability.
            $firstLineIdx = count($lines);
            $wrap($text);
            $lastLineIdx = count($lines) - 1;
            for ($li = $firstLineIdx; $li <= $lastLineIdx; $li++) {
                $lines[$li] = "\e[4;36m" . $lines[$li] . "\e[24;39m";
                $this->sidebarPathMap[$li] = $step['node_id'];
            }
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
        $bannerRows = $this->renderSourceBannerRows($this->bannerFocusId, $cols);
        $this->lastRenderBannerRowCount = count($bannerRows);
        $bottomReserve = 2 + $this->lastRenderBannerRowCount; // banner rows + footer + status

        $lines[] = $this->renderBreadcrumb($cols);
        $lines[] = $this->colHeader($cols);
        $lines[] = '  ' . str_repeat('─', min($cols - 2, 120));

        $bodyH = $totalRows - count($lines) - $bottomReserve;
        // 1-based terminal row range that holds the visible list rows;
        // recorded so hitTestMouseRow doesn't have to re-derive it from
        // potentially-stale cached values.
        $listFirstRow = count($lines) + 1;
        $count = count($this->rows);
        $visibleListCount = max(0, min($bodyH, $count - $this->topRow));
        $this->lastRenderListStart = $visibleListCount > 0 ? $listFirstRow : -1;
        $this->lastRenderListEnd = $visibleListCount > 0
            ? $listFirstRow + $visibleListCount - 1
            : -1;
        $this->lastRenderBodyEndRow = $totalRows - $bottomReserve;
        $this->lastRenderParentsStart = -1;
        $this->lastRenderParentsEnd = -1;
        $this->lastRenderChildrenStart = -1;
        $this->lastRenderChildrenEnd = -1;
        for ($i = $this->topRow; $i < min($this->topRow + $bodyH, $count); $i++) {
            $lines[] = $this->formatRow($this->rows[$i], $i === $this->selected, $cols);
        }

        while (count($lines) < $totalRows - $bottomReserve) {
            $lines[] = '';
        }

        foreach ($bannerRows as $bannerRow) {
            $lines[] = $bannerRow;
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
        $bannerRows = $this->renderSourceBannerRows($this->bannerFocusId, $cols);
        $this->lastRenderBannerRowCount = count($bannerRows);

        // Two reserves intentionally diverge:
        //   - $bottomReserve = actual rows the banner takes — used
        //     for filling the children pane down to the banner's
        //     top edge so there's no padding gap.
        //   - $halfH is computed from $maxBottomReserve (worst-
        //     case banner + footer + status) so the focus bar
        //     stays at a constant y-position even as the cursor
        //     walks through nodes with different actual kind
        //     counts. The children pane absorbs the slack rows
        //     when the banner is shorter than the maximum.
        $bottomReserve = 2 + $this->lastRenderBannerRowCount;
        $maxBottomReserve = 2 + $this->maxBannerReserve();
        $this->lastRenderBodyEndRow = $totalRows - $bottomReserve;
        $this->lastRenderListStart = -1;
        $this->lastRenderListEnd = -1;

        $bodyH = $totalRows - 2 - $maxBottomReserve; // header + focus bar on top, max banner + footer + status on bottom
        $halfH = (int)($bodyH / 2);

        // Parents pane
        $pLabel = $this->activePane === 'parents' ? "\e[1m▸ Parents\e[0m" : "\e[2m  Parents\e[0m";
        $lines[] = $pLabel . sprintf(' (%d)', count($this->parentRows));
        // 1-based terminal row of the first parent data row.
        $parentsFirstRow = count($lines) + 1;
        $pCount = count($this->parentRows);
        $visibleParentCount = max(0, min($halfH, $pCount - $this->parentTopRow));
        $this->lastRenderParentsStart = $visibleParentCount > 0 ? $parentsFirstRow : -1;
        $this->lastRenderParentsEnd = $visibleParentCount > 0
            ? $parentsFirstRow + $visibleParentCount - 1
            : -1;
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
        $remainH = $totalRows - count($lines) - $bottomReserve;
        // 1-based terminal row of the first child data row.
        $childrenFirstRow = count($lines) + 1;
        $cCount = count($this->childRows);
        $visibleChildCount = max(0, min($remainH, $cCount - $this->childTopRow));
        $this->lastRenderChildrenStart = $visibleChildCount > 0 ? $childrenFirstRow : -1;
        $this->lastRenderChildrenEnd = $visibleChildCount > 0
            ? $childrenFirstRow + $visibleChildCount - 1
            : -1;
        for ($i = $this->childTopRow; $i < min($this->childTopRow + $remainH, $cCount); $i++) {
            $sel = $this->activePane === 'children' && $i === $this->childSelected;
            $lines[] = $this->formatRow($this->childRows[$i], $sel, $cols);
        }

        while (count($lines) < $totalRows - $bottomReserve) {
            $lines[] = '';
        }

        foreach ($bannerRows as $bannerRow) {
            $lines[] = $bannerRow;
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
            ? " ↑↓:sel Tab:pane Enter:focus Bksp:back g:def r:sort n:edges f:follow m:mark ':marks o:side O:mem s:top t:roots ?:help q:quit"
            : " ↑↓:sel Enter:drill Bksp:back /:filt F:search r:sort c:class y:type a:addr g:def f:follow m:mark ':marks o:side O:mem s:top t:roots q:quit";
        $followBadge = $this->followMode ? "\e[1;42;30m follow \e[0m " : '';
        $bare = $hints;
        $visibleLen = strlen($bare) + ($this->followMode ? 9 : 0); // badge rendered width
        $pad = max(0, $cols - $visibleLen);
        return $followBadge . "\e[2m" . $bare . str_repeat(' ', $pad) . "\e[0m";
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
            '    F               Global search (labels, classes, strings)',
            '    a               Jump to address (0x...) or node (#N)',
            '    g               Go to definition (func/class table)',
            '    i               Subtree info (type/class breakdown)',
            '    x               Cycle list (SCCs from sidecar)',
            '    m               Toggle bookmark on selected node',
            "    '               Show bookmarks list",
            '    r               Toggle sort: retained / link name',
            '    n               Toggle tree/all edges',
            '    o               Toggle sidebar (path to root)',
            '    O               Show memory overview (heap/limit/RSS/...)',
            '    b               Toggle source-location banner (clickable path)',
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

    /**
     * Render a centered overlay box with the heap/memory overview —
     * the same content inspector:memory:report prints under its
     * "=== Overview ===" header. Populated from setOverviewLines(),
     * which the command fills once at startup from the binary's
     * summary section via OverviewPass.
     *
     * @param list<string> &$lines
     */
    private function renderOverviewOverlay(array &$lines, int $cols, int $rows): void
    {
        $body = [
            '  Memory overview',
            '',
        ];
        foreach ($this->overviewLines as $line) {
            $body[] = '  ' . $line;
        }
        $body[] = '';
        $body[] = '  Any key to dismiss';

        $contentW = 0;
        foreach ($body as $line) {
            $w = mb_strlen($line);
            if ($w > $contentW) {
                $contentW = $w;
            }
        }
        // Budget: 4-col margin for the box plus a 2-col inner padding on
        // each side. Long overview lines (especially the pipe-joined
        // heap/vm_stack/compiler_arena trio) get clamped to the terminal
        // width so the box never runs past the edge.
        $boxW = min(max($contentW + 4, 40), max(40, $cols - 4));
        $boxH = count($body) + 2;
        $startRow = max(0, (int)(($rows - $boxH) / 2));
        $startCol = max(0, (int)(($cols - $boxW) / 2));

        for ($i = 0; $i < $boxH; $i++) {
            $lineIdx = $startRow + $i;
            if ($lineIdx >= count($lines)) {
                break;
            }
            if ($i === 0 || $i === $boxH - 1) {
                $content = str_repeat('─', $boxW);
            } else {
                $text = $body[$i - 1] ?? '';
                if (mb_strlen($text) > $boxW - 2) {
                    $text = mb_substr($text, 0, $boxW - 5) . '...';
                }
                $content = str_pad($text, $boxW);
            }
            $lines[$lineIdx] = "\e[" . ($lineIdx + 1) . ";" . ($startCol + 1) . "H"
                . "\e[47;30m" . $content . "\e[0m";
        }
    }

    private function bodyHeight(): int
    {
        [, $rows] = $this->term->size();
        return max(1, $rows - 6);
    }

    /**
     * Visible-cell width of $s, ignoring CSI escape sequences.
     * ASCII-only assumption — every non-escape byte is one cell.
     * Good enough for the sidebar paths and node labels we render
     * (no CJK / wide chars in those code paths).
     */
    private static function visibleWidth(string $s): int
    {
        $stripped = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $s);
        return strlen($stripped ?? $s);
    }

    /**
     * Truncate $s so its **visible** width is at most $max cells,
     * preserving CSI escape sequences intact (no half-cut escapes
     * leaking onto the screen as literal text). When truncation
     * actually happens, append $suffix and a `\e[0m` reset so any
     * attribute the caller's escapes left half-open doesn't bleed
     * into whatever is drawn next.
     */
    private static function truncateToVisibleWidth(string $s, int $max, string $suffix = '...'): string
    {
        if (self::visibleWidth($s) <= $max) {
            return $s;
        }
        $suffixWidth = self::visibleWidth($suffix);
        if ($suffixWidth >= $max) {
            return substr($suffix, 0, $max);
        }
        $budget = $max - $suffixWidth;
        $out = '';
        $visible = 0;
        $len = strlen($s);
        $i = 0;
        while ($i < $len && $visible < $budget) {
            // Pass CSI escapes through unmodified — they don't cost
            // visible cells. We accept the standard `\e[ <params> <final>`
            // form; final byte is in 0x40..0x7E (`@..~`).
            if ($i + 1 < $len && $s[$i] === "\e" && $s[$i + 1] === '[') {
                $j = $i + 2;
                while ($j < $len) {
                    $b = ord($s[$j]);
                    $j++;
                    if ($b >= 0x40 && $b <= 0x7E) {
                        break;
                    }
                }
                $out .= substr($s, $i, $j - $i);
                $i = $j;
                continue;
            }
            $out .= $s[$i];
            $visible++;
            $i++;
        }
        return $out . $suffix . "\e[0m";
    }
}
