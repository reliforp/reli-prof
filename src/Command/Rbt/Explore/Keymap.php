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

namespace Reli\Command\Rbt\Explore;

/**
 * Maps captured key sequences to logical actions.
 *
 * The default bindings cover both arrow keys and vim-style hjkl. Users
 * can override the map by passing `--keymap=PATH` on the command line;
 * the file is JSON of `action => [byte_sequence, ...]`. Unknown actions
 * are ignored, missing actions fall back to the default bindings.
 *
 * Sequences may include:
 *   - literal characters: "q", "/", "\n"
 *   - escape sequences:   "\u001b[A" (Up), "\u001b[B" (Down), ...
 *   - control codes:      "\u0003" (Ctrl-C), "\u0002" (Ctrl-B), ...
 */
final class Keymap
{
    public const ACTION_UP            = 'select_up';
    public const ACTION_DOWN          = 'select_down';
    public const ACTION_PAGE_UP       = 'page_up';
    public const ACTION_PAGE_DOWN     = 'page_down';
    public const ACTION_HOME          = 'home';
    public const ACTION_END           = 'end';
    public const ACTION_FOCUS_ENTER   = 'focus_enter';
    public const ACTION_CALLERS       = 'callers';
    public const ACTION_CALLEES       = 'callees';
    public const ACTION_TOGGLE_PANE   = 'toggle_pane';
    public const ACTION_TOGGLE_PANE_REVERSE = 'toggle_pane_reverse';
    public const ACTION_BACK          = 'back';
    public const ACTION_FOLLOW_OVERVIEW = 'follow_overview';
    public const ACTION_VIEW_SELF     = 'view_self';
    public const ACTION_VIEW_TOTAL    = 'view_total';
    public const ACTION_FILTER_VIEW   = 'filter_view';
    public const ACTION_FILTER_MATCH  = 'filter_match';
    public const ACTION_NO_LINE       = 'no_line';
    public const ACTION_TOGGLE_OVERVIEW = 'toggle_overview';
    public const ACTION_VIEW_OVERVIEW = 'view_overview';
    public const ACTION_TOGGLE_MINI_FLAME = 'toggle_mini_flame';
    public const ACTION_VIEW_PANES        = 'view_panes';
    public const ACTION_VIEW_FLAME        = 'view_flame';
    public const ACTION_VIEW_TREE_CALLEES = 'view_tree_callees';
    public const ACTION_VIEW_TREE_CALLERS = 'view_tree_callers';
    public const ACTION_TREE_FOLD_RECURSIVE   = 'tree_fold_recursive';
    public const ACTION_TREE_UNFOLD_RECURSIVE = 'tree_unfold_recursive';
    public const ACTION_TOGGLE_FLAME_LABEL_ALIGN = 'toggle_flame_label_align';
    public const ACTION_HELP          = 'help';
    public const ACTION_QUIT          = 'quit';

    /** @var array<string, list<string>> action => list of byte sequences */
    private array $bindings;

    /** @var array<string, string> sequence => action (lookup index) */
    private array $by_sequence;

    /**
     * @param array<string, list<string>> $bindings
     */
    public function __construct(array $bindings)
    {
        $this->bindings = $bindings;
        $this->by_sequence = [];
        foreach ($bindings as $action => $sequences) {
            foreach ($sequences as $seq) {
                $this->by_sequence[$seq] = $action;
            }
        }
    }

    public static function default(): self
    {
        return new self([
            self::ACTION_UP            => ["\e[A", 'k'],
            self::ACTION_DOWN          => ["\e[B", 'j'],
            self::ACTION_PAGE_UP       => ["\e[5~", "\x02"],
            self::ACTION_PAGE_DOWN     => ["\e[6~", "\x06", ' '],
            self::ACTION_HOME          => ["\e[H", "\e[1~", 'g'],
            self::ACTION_END           => ["\e[F", "\e[4~", 'G'],
            self::ACTION_FOCUS_ENTER   => ["\r", "\n"],
            self::ACTION_CALLERS       => ["\e[D", 'h'],
            self::ACTION_CALLEES       => ["\e[C", 'l'],
            self::ACTION_TOGGLE_PANE   => ["\t"],
            self::ACTION_TOGGLE_PANE_REVERSE => ["\e[Z"],
            self::ACTION_FOLLOW_OVERVIEW => ['f'],
            self::ACTION_BACK          => ['u', "\x7f", "\b"],
            self::ACTION_VIEW_SELF     => ['s'],
            self::ACTION_VIEW_TOTAL    => ['t'],
            self::ACTION_FILTER_VIEW   => ['/'],
            self::ACTION_FILTER_MATCH  => ['m'],
            self::ACTION_NO_LINE       => ['n'],
            self::ACTION_TOGGLE_OVERVIEW => ['o'],
            self::ACTION_VIEW_OVERVIEW => ['O'],
            self::ACTION_TOGGLE_MINI_FLAME => ['F'],
            self::ACTION_VIEW_PANES        => ['P'],
            self::ACTION_VIEW_FLAME        => ['S'],
            self::ACTION_VIEW_TREE_CALLEES => ['>'],
            self::ACTION_VIEW_TREE_CALLERS => ['<'],
            self::ACTION_TREE_FOLD_RECURSIVE   => ['H'],
            self::ACTION_TREE_UNFOLD_RECURSIVE => ['L'],
            self::ACTION_TOGGLE_FLAME_LABEL_ALIGN => ['A'],
            self::ACTION_HELP          => ['?'],
            self::ACTION_QUIT          => ['q', "\x03"],
        ]);
    }

    /**
     * Load a user keymap, falling back to default bindings for any
     * action the file does not override.
     */
    public static function fromJsonFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read keymap file: {$path}");
        }
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Keymap file is not a JSON object: {$path}");
        }

        $bindings = self::default()->bindings;
        /** @var mixed $sequences */
        foreach ($decoded as $action => $sequences) {
            if (!is_string($action) || !is_array($sequences)) {
                continue;
            }
            $clean = [];
            /** @var mixed $s */
            foreach ($sequences as $s) {
                if (is_string($s) && $s !== '') {
                    $clean[] = $s;
                }
            }
            if ($clean !== []) {
                $bindings[$action] = $clean;
            }
        }
        return new self($bindings);
    }

    public function resolve(string $sequence): ?string
    {
        return $this->by_sequence[$sequence] ?? null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }
}
