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

namespace Reli\Inspector\Watch\Dump;

use Reli\Inspector\Watch\VariableValue;

/**
 * Renders a VariableValue tree in PHP's serialize() format.
 *
 * Compat caveats — the output is **best-effort** and may not always
 * round-trip through `unserialize()`:
 *
 *   - `serialize()` numbers every emitted entry and rewrites back-edges as
 *     `r:N;` (objects use `R:N;`). Producing those numbers correctly would
 *     require a pre-pass over the tree; we instead emit `N;` for cycles
 *     (TYPE_RECURSION). The result still parses but loses identity.
 *   - Element-truncated arrays/objects keep the header count in sync with
 *     the emitted children, so `unserialize()` accepts the prefix even
 *     though information is missing.
 *   - Strings clipped to fit max_string emit their *clipped* byte length,
 *     so the format stays syntactically valid; the original length is
 *     therefore unrecoverable from the dump.
 *   - Custom Serializable / __serialize() output cannot be reconstructed
 *     from a memory snapshot — objects always render as plain
 *     `O:len:"Class":N:{...}`.
 *
 * Use this format when you want a compact, machine-parseable single-line
 * dump (logs, eyeball-diffing). For round-tripping back into a PHP value,
 * pull the data live with `serialize()` instead.
 */
final class PhpSerializeFormatter
{
    public static function format(VariableValue $value): string
    {
        return self::render($value);
    }

    private static function render(VariableValue $value): string
    {
        return match ($value->type) {
            VariableValue::TYPE_LONG => 'i:' . (string)$value->scalar_value . ';',
            VariableValue::TYPE_DOUBLE => 'd:' . self::formatDouble($value->scalar_value) . ';',
            VariableValue::TYPE_STRING => self::renderString((string)$value->scalar_value),
            VariableValue::TYPE_BOOL => 'b:' . ($value->scalar_value === true ? '1' : '0') . ';',
            VariableValue::TYPE_NULL => 'N;',
            VariableValue::TYPE_ARRAY => self::renderArray($value),
            VariableValue::TYPE_OBJECT => self::renderObject($value),
            VariableValue::TYPE_RECURSION => 'N;',
            default => 'N;',
        };
    }

    private static function formatDouble(mixed $v): string
    {
        if (!is_int($v) && !is_float($v)) {
            return '0';
        }
        if (is_float($v)) {
            if (is_nan($v)) {
                return 'NAN';
            }
            if (!is_finite($v)) {
                return $v > 0 ? 'INF' : '-INF';
            }
        }
        return (string)$v;
    }

    private static function renderString(string $s): string
    {
        return 's:' . strlen($s) . ':"' . $s . '";';
    }

    private static function renderArray(VariableValue $value): string
    {
        $children = $value->children ?? [];
        $body = '';
        foreach ($children as [$key, $child]) {
            $body .= self::renderKey($key) . self::render($child);
        }
        return 'a:' . count($children) . ':{' . $body . '}';
    }

    private static function renderObject(VariableValue $value): string
    {
        $class = $value->class_name ?? 'stdClass';
        $children = $value->children ?? [];
        $body = '';
        foreach ($children as [$key, $child]) {
            // For object props, the key is always emitted as a string, even
            // for numeric keys (matching serialize() behavior).
            $key_str = is_int($key) ? (string)$key : $key;
            $body .= self::renderString($key_str) . self::render($child);
        }
        return 'O:' . strlen($class) . ':"' . $class . '":'
            . count($children) . ':{' . $body . '}';
    }

    private static function renderKey(int|string $key): string
    {
        if (is_int($key)) {
            return 'i:' . (string)$key . ';';
        }
        return self::renderString($key);
    }
}
