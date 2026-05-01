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
 * Renders a VariableValue tree in PHP's var_dump() format.
 *
 * Mirrors the runtime output, with caveats for limits:
 *   - depth-truncated arrays/objects show a single `...` placeholder line
 *     after the count header, matching what `var_dump()` itself does for
 *     nothing (var_dump has no real depth limit) but communicates "more
 *     content elided" without breaking the {N} block shape.
 *   - element-truncated arrays/objects append a `// ... (N more)` comment
 *     inside the block.
 *   - strings show the *original* byte length even when the value was
 *     clipped to fit max_string; clipped values are followed by `"...`.
 *
 * Cycles render as `*RECURSION*` (matches var_dump).
 */
final class VarDumpFormatter
{
    public static function format(VariableValue $value): string
    {
        return self::render($value, 0);
    }

    private static function render(VariableValue $value, int $indent): string
    {
        return match ($value->type) {
            VariableValue::TYPE_LONG => 'int(' . (string)$value->scalar_value . ')',
            VariableValue::TYPE_DOUBLE => 'float(' . self::formatDouble($value->scalar_value) . ')',
            VariableValue::TYPE_STRING => self::renderString($value),
            VariableValue::TYPE_BOOL => 'bool(' . ($value->scalar_value === true ? 'true' : 'false') . ')',
            VariableValue::TYPE_NULL => 'NULL',
            VariableValue::TYPE_ARRAY => self::renderArray($value, $indent),
            VariableValue::TYPE_OBJECT => self::renderObject($value, $indent),
            VariableValue::TYPE_RECURSION => '*RECURSION*',
            default => '(unknown)',
        };
    }

    private static function formatDouble(mixed $v): string
    {
        if (!is_int($v) && !is_float($v)) {
            return '0';
        }
        if (is_float($v) && is_finite($v) && (float)(int)$v === $v) {
            // var_dump prints "float(1)" for 1.0, matching PHP's behavior.
            return (string)(int)$v;
        }
        return (string)$v;
    }

    private static function renderString(VariableValue $value): string
    {
        $raw = (string)$value->scalar_value;
        $length = $value->original_string_length ?? strlen($raw);
        $body = '"' . $raw;
        if ($value->string_truncated) {
            $body .= '..."';
        } else {
            $body .= '"';
        }
        return 'string(' . $length . ') ' . $body;
    }

    private static function renderArray(VariableValue $value, int $indent): string
    {
        $count = $value->array_count ?? 0;
        $header = 'array(' . $count . ') {';
        if ($value->children === null) {
            // Depth-limited shallow read.
            return $header . self::eol($indent + 1) . '...'
                . self::eol($indent) . '}';
        }
        return $header
            . self::renderChildren($value, $indent, false)
            . self::eol($indent) . '}';
    }

    private static function renderObject(VariableValue $value, int $indent): string
    {
        $class = $value->class_name ?? '(unknown)';
        $id = $value->object_id ?? 0;
        $count = $value->array_count ?? 0;
        $header = 'object(' . $class . ')#' . $id . ' (' . $count . ') {';
        if ($value->children === null) {
            return $header . self::eol($indent + 1) . '...'
                . self::eol($indent) . '}';
        }
        return $header
            . self::renderChildren($value, $indent, true)
            . self::eol($indent) . '}';
    }

    private static function renderChildren(
        VariableValue $value,
        int $indent,
        bool $is_object,
    ): string {
        $out = '';
        foreach ($value->children ?? [] as [$key, $child]) {
            $out .= self::eol($indent + 1)
                . self::renderKey($key, $is_object)
                . self::eol($indent + 1)
                . self::render($child, $indent + 1);
        }
        if ($value->children_truncated) {
            $out .= self::eol($indent + 1) . '// ... (truncated)';
        }
        return $out;
    }

    private static function renderKey(int|string $key, bool $is_object): string
    {
        if ($is_object && is_string($key)) {
            // Detect NUL-prefixed visibility markers from
            // ZendObject::getPropertiesIterator.
            if (str_starts_with($key, "\0")) {
                $rest = substr($key, 1);
                $sep = strpos($rest, "\0");
                if ($sep !== false) {
                    $owner = substr($rest, 0, $sep);
                    $name = substr($rest, $sep + 1);
                    if ($owner === '*') {
                        return '["' . $name . '":protected]=>';
                    }
                    return '["' . $name . '":"' . $owner . '":private]=>';
                }
            }
            return '["' . $key . '"]=>';
        }
        if (is_int($key)) {
            return '[' . (string)$key . ']=>';
        }
        return '["' . $key . '"]=>';
    }

    private static function eol(int $indent): string
    {
        return "\n" . str_repeat('  ', $indent);
    }
}
