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
 * Renders a VariableValue tree in PHP's var_export() format.
 *
 * Caveats vs. native var_export():
 *   - var_export() raises a fatal error on recursive structures; we instead
 *     emit `/* RECURSION *\/` so the dump still completes.
 *   - Depth-truncated and element-truncated nodes emit a `/* ... *\/`
 *     comment inside the array/object body.
 *   - Strings clipped to fit max_string are followed by `...'` rather than
 *     a closing single quote so the truncation is visible in the output.
 *
 * The output for fully-readable scalars/arrays/objects is byte-identical
 * to what `var_export()` would emit for the same value (single-quoted
 * strings, `array (...)` blocks, `\Class::__set_state(array(...))` for
 * objects).
 */
final class VarExportFormatter
{
    public static function format(VariableValue $value): string
    {
        return self::render($value, 0);
    }

    private static function render(VariableValue $value, int $indent): string
    {
        return match ($value->type) {
            VariableValue::TYPE_LONG => (string)$value->scalar_value,
            VariableValue::TYPE_DOUBLE => self::formatDouble($value->scalar_value),
            VariableValue::TYPE_STRING => self::renderString($value),
            VariableValue::TYPE_BOOL => $value->scalar_value === true ? 'true' : 'false',
            VariableValue::TYPE_NULL => 'NULL',
            VariableValue::TYPE_ARRAY => self::renderArray($value, $indent),
            VariableValue::TYPE_OBJECT => self::renderObject($value, $indent),
            VariableValue::TYPE_RECURSION => '/* RECURSION */',
            default => '/* unknown */',
        };
    }

    private static function formatDouble(mixed $v): string
    {
        if (!is_int($v) && !is_float($v)) {
            return '0.0';
        }
        if (is_float($v) && is_finite($v) && (float)(int)$v === $v) {
            return (string)(int)$v . '.0';
        }
        return (string)$v;
    }

    private static function renderString(VariableValue $value): string
    {
        $raw = (string)$value->scalar_value;
        $escaped = str_replace(
            ['\\', '\''],
            ['\\\\', '\\\''],
            $raw,
        );
        if ($value->string_truncated) {
            return "'" . $escaped . "...'";
        }
        return "'" . $escaped . "'";
    }

    private static function renderArray(VariableValue $value, int $indent): string
    {
        if ($value->children === null) {
            return 'array (' . self::eol($indent + 1) . '/* ... */'
                . self::eol($indent) . ')';
        }
        if ($value->children === []) {
            return 'array (' . ($value->children_truncated
                ? self::eol($indent + 1) . '/* ... */' . self::eol($indent)
                : self::nbsp())
                . ')';
        }
        $body = '';
        foreach ($value->children as [$key, $child]) {
            $body .= self::eol($indent + 1)
                . self::renderKey($key) . ' => '
                . self::renderChildValue($child, $indent + 1) . ',';
        }
        if ($value->children_truncated) {
            $body .= self::eol($indent + 1) . '/* ... */';
        }
        return 'array (' . $body . self::eol($indent) . ')';
    }

    private static function renderObject(VariableValue $value, int $indent): string
    {
        $class = $value->class_name ?? 'stdClass';
        $prefix = '\\' . ltrim($class, '\\') . '::__set_state(array(';
        if ($value->children === null) {
            return $prefix . self::eol($indent + 1) . '/* ... */'
                . self::eol($indent) . '))';
        }
        if ($value->children === []) {
            return $prefix . ($value->children_truncated
                ? self::eol($indent + 1) . '/* ... */' . self::eol($indent)
                : self::nbsp())
                . '))';
        }
        $body = '';
        foreach ($value->children as [$key, $child]) {
            $body .= self::eol($indent + 1)
                . self::renderObjectKey($key) . ' => '
                . self::renderChildValue($child, $indent + 1) . ',';
        }
        if ($value->children_truncated) {
            $body .= self::eol($indent + 1) . '/* ... */';
        }
        return $prefix . $body . self::eol($indent) . '))';
    }

    /**
     * Like {@see render} but inserts a leading newline + same-level indent
     * before non-empty arrays/objects, matching var_export()'s habit of
     * pushing complex children onto a new line.
     */
    private static function renderChildValue(VariableValue $value, int $indent): string
    {
        $needs_break = ($value->type === VariableValue::TYPE_ARRAY
            || $value->type === VariableValue::TYPE_OBJECT)
            && ($value->children === null || $value->children !== []);
        $rendered = self::render($value, $indent);
        if ($needs_break) {
            return self::eol($indent) . $rendered;
        }
        return $rendered;
    }

    private static function renderKey(int|string $key): string
    {
        if (is_int($key)) {
            return (string)$key;
        }
        return self::quote($key);
    }

    private static function renderObjectKey(int|string $key): string
    {
        if (is_int($key)) {
            return self::quote((string)$key);
        }
        // var_export strips visibility markers and just shows the bare name.
        if (str_starts_with($key, "\0")) {
            $rest = substr($key, 1);
            $sep = strpos($rest, "\0");
            if ($sep !== false) {
                return self::quote(substr($rest, $sep + 1));
            }
        }
        return self::quote($key);
    }

    private static function quote(string $s): string
    {
        $escaped = str_replace(
            ['\\', '\''],
            ['\\\\', '\\\''],
            $s,
        );
        return "'" . $escaped . "'";
    }

    private static function nbsp(): string
    {
        return '';
    }

    private static function eol(int $indent): string
    {
        return "\n" . str_repeat('  ', $indent);
    }
}
