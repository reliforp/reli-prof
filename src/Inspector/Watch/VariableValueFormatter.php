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

namespace Reli\Inspector\Watch;

/**
 * Renders a VariableValue into a single-line, line-safe string.
 *
 * Used by inspector:trace --trace-var to produce annotation values that are
 * embedded in phpspy text output (as `# key = value` comment lines) and in
 * rbt SAMPLE_ANNOTATION events. A single renderer keeps both formats in
 * lockstep.
 *
 * Output shape:
 *   TYPE_LONG    → "(int) 42"
 *   TYPE_DOUBLE  → "(float) 3.14"
 *   TYPE_STRING  → "(string) \"hello\"" (JSON-escaped, capped at max_length)
 *   TYPE_BOOL    → "(bool) true" / "(bool) false"
 *   TYPE_ARRAY   → "(array) count=10"
 *   TYPE_NULL    → "null"
 *   TYPE_UNKNOWN → "(unknown)"
 *
 * All outputs are guaranteed to contain no raw LF, CR, or unescaped control
 * characters — the JSON encoding of the string branch handles UTF-8 and
 * embedded newlines, and the other branches are ASCII-only by construction.
 */
final class VariableValueFormatter
{
    public const DEFAULT_MAX_STRING_LENGTH = 200;

    public static function format(
        VariableValue $value,
        int $max_string_length = self::DEFAULT_MAX_STRING_LENGTH,
    ): string {
        return match ($value->type) {
            VariableValue::TYPE_LONG => '(int) ' . (string)$value->scalar_value,
            VariableValue::TYPE_DOUBLE => '(float) ' . self::formatDouble($value->scalar_value),
            VariableValue::TYPE_STRING => '(string) ' . self::formatString(
                (string)$value->scalar_value,
                $max_string_length,
            ),
            VariableValue::TYPE_BOOL => '(bool) ' . ($value->scalar_value === true ? 'true' : 'false'),
            VariableValue::TYPE_ARRAY => '(array) count=' . ($value->array_count ?? 0),
            VariableValue::TYPE_NULL => 'null',
            default => '(unknown)',
        };
    }

    private static function formatDouble(mixed $scalar_value): string
    {
        if (!is_float($scalar_value) && !is_int($scalar_value)) {
            return '0';
        }
        // (string) on a float uses precision ini setting; good enough for
        // human-readable annotations. NaN/Inf map to "NAN" / "INF" which
        // are line-safe and unambiguous.
        return (string)$scalar_value;
    }

    private static function formatString(string $raw, int $max_length): string
    {
        if ($max_length > 0 && mb_strlen($raw) > $max_length) {
            $raw = mb_substr($raw, 0, $max_length) . '...';
        }
        // JSON encoding guarantees: quotes around the value, backslash-
        // escaped control chars (including LF/CR), and UTF-8 safety.
        // JSON_UNESCAPED_UNICODE keeps non-ASCII readable rather than
        // emitting \uXXXX sequences.
        $encoded = json_encode($raw, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            // Fall back to an opaque marker rather than dropping the line.
            // json_encode can fail on invalid UTF-8 sequences; in that case
            // we lose the string content but keep the annotation structure
            // intact.
            return '"<invalid-utf8>"';
        }
        return $encoded;
    }
}
