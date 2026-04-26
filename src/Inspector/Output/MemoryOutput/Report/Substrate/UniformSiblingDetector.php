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

namespace Reli\Inspector\Output\MemoryOutput\Report\Substrate;

/**
 * Detect runs of consecutive rows that look like "the same thing N times":
 * same size (±tolerance), same auxiliary count (element_count for arrays),
 * and PHP-syntax paths that differ in exactly one token.
 *
 * Top Arrays / Top Strings tables in real captures often dump 6–25
 * sibling rows that read as one phenomenon — `$rows[0]`, `$rows[1]`,
 * ... or `class_table->Carbon\X->doc_comment` for several X. Collapsing
 * the run to one representative + annotation keeps the table scannable
 * without losing the count or the spread of varying values.
 *
 * Tokenization splits on the natural PHP path boundaries: `$var`, `[K]`,
 * `->prop`. A run is extended only while the *same* token position is the
 * differing one — so `$a[0]` / `$a[1]` collapses, but `$a[0]` / `$b[0]`
 * doesn't (different first token), and `$a[0]->x` / `$a[0]->y` collapses
 * (third token varies). Tolerance is on retained size only; auxiliary
 * counts (element_count) must match exactly.
 *
 * The detector only finds *consecutive* runs. The Top Arrays / Top
 * Strings tables come pre-sorted by retained size descending, so similar
 * siblings naturally sit adjacent — non-adjacent matches are rare and
 * deliberately not collapsed (sorting them under one heading would
 * scramble the rank order).
 */
final class UniformSiblingDetector
{
    /**
     * Scan parallel arrays of rows and decide, per row, whether to render
     * normally, render as the head of a collapsed run, or skip (collapsed
     * into the previous head).
     *
     * `$extras` lets the caller require an exact match on a secondary
     * field (Top Arrays passes element_count; Top Strings passes 0
     * uniformly so the field is a no-op). Pass `0.05` for the default
     * 5% size tolerance.
     *
     * @param list<int>    $sizes
     * @param list<int>    $extras  must be the same length as `$sizes`
     * @param list<string> $paths   PHP-syntax paths (already formatted)
     * @return array<int, array{
     *   kind: 'normal'|'head'|'member',
     *   annotation?: string,
     * }>
     */
    public static function findRuns(
        array $sizes,
        array $extras,
        array $paths,
        float $size_tolerance = 0.05,
    ): array {
        $n = count($sizes);
        if ($n !== count($extras) || $n !== count($paths)) {
            // Defensive: callers in this codebase always pass equal-length
            // arrays, but a length mismatch is ambiguous (which row is
            // which?) so degrade to "render everything normally".
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = ['kind' => 'normal'];
            }
            return $out;
        }

        /** @var array<int, array{kind: 'normal'|'head'|'member', annotation?: string}> $decisions */
        $decisions = [];
        for ($i = 0; $i < $n; $i++) {
            $decisions[$i] = ['kind' => 'normal'];
        }

        $i = 0;
        while ($i < $n) {
            $head_tokens = self::tokenize($paths[$i]);
            $varying_idx = null;
            $run_values = [$head_tokens];
            $j = $i + 1;
            while ($j < $n) {
                if ($extras[$j] !== $extras[$i]) {
                    break;
                }
                if (!self::sizesMatch($sizes[$i], $sizes[$j], $size_tolerance)) {
                    break;
                }
                $cur_tokens = self::tokenize($paths[$j]);
                if (count($cur_tokens) !== count($head_tokens)) {
                    break;
                }
                $diff_idx = self::singleDiffPosition($head_tokens, $cur_tokens);
                if ($diff_idx === null) {
                    break;
                }
                if ($varying_idx === null) {
                    $varying_idx = $diff_idx;
                } elseif ($varying_idx !== $diff_idx) {
                    break;
                }
                $run_values[] = $cur_tokens;
                $j++;
            }

            $extension = $j - $i - 1;
            if ($extension >= 1 && $varying_idx !== null) {
                $values = [];
                foreach ($run_values as $tokens) {
                    $values[] = $tokens[$varying_idx];
                }
                $annotation = self::buildAnnotation(
                    $extension,
                    $sizes[$i],
                    $values,
                    $head_tokens[$varying_idx],
                );
                $decisions[$i] = ['kind' => 'head', 'annotation' => $annotation];
                for ($k = $i + 1; $k < $j; $k++) {
                    $decisions[$k] = ['kind' => 'member'];
                }
            }
            $i = $j;
        }

        return $decisions;
    }

    /**
     * Split a PHP-syntax path into the tokens that bound a "segment":
     * `$var`, `[K]`, `->prop`. Falls back to a single token when the
     * path doesn't match the expected shape (e.g. `(root)`); that
     * still works correctly with the run detector — a single-token
     * path can only collapse against another single-token path that
     * differs entirely, which is the right behaviour.
     *
     * @return list<string>
     */
    private static function tokenize(string $path): array
    {
        if ($path === '') {
            return [''];
        }
        if (preg_match_all('/\$[^\->\[]+|->[^\->\[]+|\[[^\]]*\]/', $path, $matches) === false) {
            return [$path];
        }
        $tokens = $matches[0];
        if ($tokens === [] || implode('', $tokens) !== $path) {
            // Tokenizer didn't cover the whole string — paths with `::`
            // (call frames) or `<main>:line` prefixes fall here. Treat
            // the whole path as one token; runs only collapse when two
            // such paths happen to be identical, which won't trigger.
            return [$path];
        }
        return $tokens;
    }

    /**
     * Position of the single differing token, or null when the two
     * token lists differ at zero positions or more than one position.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function singleDiffPosition(array $a, array $b): ?int
    {
        if (count($a) !== count($b)) {
            return null;
        }
        $diff = -1;
        for ($i = 0; $i < count($a); $i++) {
            if ($a[$i] !== $b[$i]) {
                if ($diff !== -1) {
                    return null;
                }
                $diff = $i;
            }
        }
        return $diff === -1 ? null : $diff;
    }

    private static function sizesMatch(int $a, int $b, float $tolerance): bool
    {
        if ($a === $b) {
            return true;
        }
        $reference = max(1, max($a, $b));
        return abs($a - $b) <= (int)floor((float)$reference * $tolerance);
    }

    /**
     * Build the "+N more similar siblings ..." line that follows the
     * representative row. When all varying values are integer keys
     * (`[123]`), render as a numeric range (`indices [0..6]`); otherwise
     * list the bare values, capped at 4 examples.
     *
     * @param list<string> $varying_token_values
     */
    private static function buildAnnotation(
        int $extension_count,
        int $representative_size,
        array $varying_token_values,
        string $head_varying_token,
    ): string {
        $size_repr = SizeFormatter::format($representative_size);
        $total = $extension_count + 1;

        $numerics = self::extractIntegerKeys($varying_token_values);
        if ($numerics !== null && $numerics !== []) {
            $min = min($numerics);
            $max = max($numerics);
            return sprintf(
                '(+%d more similar siblings, ~%s each across %d rows; indices [%d..%d])',
                $extension_count,
                $size_repr,
                $total,
                $min,
                $max,
            );
        }

        $bare = [];
        foreach ($varying_token_values as $token) {
            $bare[] = self::bareTokenValue($token);
        }
        $sample = array_slice($bare, 0, 4);
        $more = count($bare) > 4 ? sprintf(', ...+%d more', count($bare) - 4) : '';
        $kind = self::tokenKindLabel($head_varying_token);
        return sprintf(
            '(+%d more similar siblings, ~%s each across %d rows; varying %s: %s%s)',
            $extension_count,
            $size_repr,
            $total,
            $kind,
            implode(', ', $sample),
            $more,
        );
    }

    /**
     * If every token is a bracketed integer key (`[42]`, `[-1]`),
     * return the integer list. Returns null when any token is not
     * a pure integer key, so the caller falls back to enumerating
     * values verbatim.
     *
     * @param list<string> $tokens
     * @return list<int>|null
     */
    private static function extractIntegerKeys(array $tokens): ?array
    {
        $out = [];
        foreach ($tokens as $token) {
            if (preg_match('/^\[(-?\d+)\]$/', $token, $m) !== 1) {
                return null;
            }
            $out[] = (int)$m[1];
        }
        return $out;
    }

    private static function bareTokenValue(string $token): string
    {
        if (str_starts_with($token, '->')) {
            return substr($token, 2);
        }
        if (str_starts_with($token, '[') && str_ends_with($token, ']')) {
            return substr($token, 1, -1);
        }
        return $token;
    }

    private static function tokenKindLabel(string $token): string
    {
        if (str_starts_with($token, '->')) {
            return 'property';
        }
        if (str_starts_with($token, '[')) {
            return 'key';
        }
        return 'name';
    }
}
