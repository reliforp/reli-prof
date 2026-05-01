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
 * Reproduces serialize's DFS-order numbering so that back-edges round-trip
 * through `unserialize()`:
 *
 *   - object cycles and *shared* (non-cyclic) object instances render as
 *     `r:N;` pointing to the id of the first emission, mirroring PHP's
 *     by-reference object semantics
 *   - `&`-aliased slots (the same `zend_reference` reachable from multiple
 *     places — including scalars) render as `R:N;` for every occurrence
 *     after the first; the first occurrence emits its target value
 *     normally and the reference's id piggybacks on that value's id, the
 *     same way PHP's serialize does it
 *   - array cycles (only reachable through `&` aliasing in PHP) render as
 *     `R:N;` via the same mechanism
 *   - if the cycle / share target is missing — e.g. it sat below a depth
 *     / element budget so the reader never assigned it an id — the
 *     back-edge falls back to `N;` (a benign placeholder that keeps the
 *     format parseable)
 *
 * Compat caveats — what we *can't* reconstruct from a memory snapshot:
 *
 *   - element-truncated arrays/objects: the header count is rewritten to
 *     match the emitted body so `unserialize()` accepts the prefix; the
 *     elided entries are unrecoverable
 *   - clipped strings: the byte length reflects the *clipped* size, so
 *     the format stays syntactically valid; the original length is lost
 *   - `Serializable` / `__serialize()` customization: custom payloads
 *     can't be reconstructed, so objects always render as
 *     `O:len:"Class":N:{...}` from raw properties
 */
final class PhpSerializeFormatter
{
    private int $counter = 0;
    /** @var array<int, int> address → id (objects, persistent for the whole call) */
    private array $object_ids = [];
    /** @var array<int, int> address → id (arrays, only valid while on the recursion path) */
    private array $array_stack_ids = [];
    /** @var array<int, int> zend_reference address → id (persistent for the whole call) */
    private array $reference_ids = [];

    public static function format(VariableValue $value): string
    {
        return (new self())->render($value);
    }

    private function render(VariableValue $value): string
    {
        // Reference-share back-edge: a slot whose zend_reference we've
        // already emitted under some id N. PHP serialize() does this even
        // for scalars (`R:N;`), and it does *not* bump the counter. We
        // never assign a separate id to the reference itself — the id
        // belongs to the target value, and the reference's address just
        // becomes another lookup key for the same id.
        $ref_addr = $value->reference_address;
        if ($ref_addr !== null && isset($this->reference_ids[$ref_addr])) {
            return 'R:' . $this->reference_ids[$ref_addr] . ';';
        }
        $before_counter = $this->counter;
        $rendered = $this->dispatch($value);
        // First-time reference: piggyback its id on whichever id
        // dispatch() consumed (if any). If dispatch emitted a back-edge
        // itself — e.g. an object that was already seen — counter didn't
        // move, and we leave reference_ids alone so a future occurrence
        // still resolves to the underlying object's id via object_ids.
        if ($ref_addr !== null && $this->counter > $before_counter) {
            $this->reference_ids[$ref_addr] = $before_counter + 1;
        }
        return $rendered;
    }

    private function dispatch(VariableValue $value): string
    {
        return match ($value->type) {
            VariableValue::TYPE_LONG => $this->scalarBump('i:' . (string)$value->scalar_value . ';'),
            VariableValue::TYPE_DOUBLE => $this->scalarBump('d:' . self::formatDouble($value->scalar_value) . ';'),
            VariableValue::TYPE_STRING => $this->scalarBump(self::renderString((string)$value->scalar_value)),
            VariableValue::TYPE_BOOL => $this->scalarBump('b:' . ($value->scalar_value === true ? '1' : '0') . ';'),
            VariableValue::TYPE_NULL => $this->scalarBump('N;'),
            VariableValue::TYPE_ARRAY => $this->renderArray($value),
            VariableValue::TYPE_OBJECT => $this->renderObject($value),
            VariableValue::TYPE_RECURSION => $this->renderRecursion($value),
            default => $this->scalarBump('N;'),
        };
    }

    private function scalarBump(string $emitted): string
    {
        $this->counter++;
        return $emitted;
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

    private function renderArray(VariableValue $value): string
    {
        $address = $value->address;
        if ($address !== null && isset($this->array_stack_ids[$address])) {
            // Back-edge to an array we're still inside — R:N;.
            // No counter bump for back-edges.
            return 'R:' . $this->array_stack_ids[$address] . ';';
        }
        $this->counter++;
        $id = $this->counter;
        if ($address !== null) {
            $this->array_stack_ids[$address] = $id;
        }
        $children = $value->children ?? [];
        $body = '';
        foreach ($children as [$key, $child]) {
            // Keys don't get counted — only the value below.
            $body .= self::renderKey($key) . $this->render($child);
        }
        if ($address !== null) {
            unset($this->array_stack_ids[$address]);
        }
        return 'a:' . count($children) . ':{' . $body . '}';
    }

    private function renderObject(VariableValue $value): string
    {
        $address = $value->address;
        if ($address !== null && isset($this->object_ids[$address])) {
            // Object identity is preserved across siblings as well — every
            // subsequent occurrence of the same instance is r:N;.
            return 'r:' . $this->object_ids[$address] . ';';
        }
        $this->counter++;
        $id = $this->counter;
        if ($address !== null) {
            $this->object_ids[$address] = $id;
        }
        $class = $value->class_name ?? 'stdClass';
        $children = $value->children ?? [];
        $body = '';
        foreach ($children as [$key, $child]) {
            // Object property keys are emitted as strings even for numeric
            // names (matches serialize() behavior). Keys themselves aren't
            // counted; the property value below is.
            $key_str = is_int($key) ? (string)$key : $key;
            $body .= self::renderString($key_str) . $this->render($child);
        }
        return 'O:' . strlen($class) . ':"' . $class . '":'
            . count($children) . ':{' . $body . '}';
    }

    /**
     * The reader emits TYPE_RECURSION for back-edges it caught during
     * traversal. We resolve which kind of back-edge it is by looking the
     * target address up in our id tables.
     */
    private function renderRecursion(VariableValue $value): string
    {
        $address = $value->address;
        if ($address !== null) {
            if (isset($this->object_ids[$address])) {
                return 'r:' . $this->object_ids[$address] . ';';
            }
            if (isset($this->array_stack_ids[$address])) {
                return 'R:' . $this->array_stack_ids[$address] . ';';
            }
        }
        // Address unknown to us — emit a parseable placeholder. Counter
        // isn't bumped because PHP doesn't bump it for back-edges either,
        // and N; here is a substitute for what would be one.
        return 'N;';
    }

    private static function renderKey(int|string $key): string
    {
        if (is_int($key)) {
            return 'i:' . (string)$key . ';';
        }
        return self::renderString($key);
    }
}
