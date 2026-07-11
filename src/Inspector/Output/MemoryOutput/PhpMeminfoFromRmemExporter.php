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

namespace Reli\Inspector\Output\MemoryOutput;

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict;

/**
 * Re-emits a .rmem object graph as a php-meminfo compatible JSON dump
 * (https://github.com/BitOne/php-meminfo), so that existing analysis
 * infrastructure built around `bin/analyzer` (summary / query /
 * ref-path / top-children) and other php-meminfo consumers can be
 * pointed at reli captures unchanged.
 *
 * Output shape (matching the C extension's meminfo_dump()):
 *
 *   {"header": {"memory_usage": N, "memory_usage_real": N,
 *               "peak_memory_usage": N, "peak_memory_usage_real": N},
 *    "items": {"0x7f..": {"type": "object", "size": "72",
 *              ["symbol_name": "var", ] "is_root": true|false,
 *              ["frame": "func()", ] ["class": "Foo",
 *              "object_handle": "1", ] ["children": {"prop": "0x7f.."}]},
 *              ...}}
 *
 * Mapping notes:
 *  - Items are PHP values only (objects, arrays, strings, scalars,
 *    resources). Reli's structural nodes (property/element tables,
 *    zend_reference wrappers) are collapsed away so that an object's
 *    children map directly from property name to value, and an
 *    array's from key to value — exactly like php-meminfo.
 *  - zend_reference indirection is followed transparently, mirroring
 *    the ZVAL_DEREF the C extension performs.
 *  - Roots are call-frame variables (frame label "func()", the
 *    outermost frame is "<GLOBAL>"), global variables ("<GLOBAL>"),
 *    and class static properties ("<CLASS_STATIC_MEMBER>", symbol
 *    "Class::prop") — the same root set php-meminfo dumps.
 *  - Additionally, every object in the objects store becomes an item
 *    even when unreachable from any root (php-meminfo cannot see
 *    those; for reli they are free coverage, e.g. cycle garbage).
 *  - `object_handle` is recovered from the objects-store bucket index,
 *    which IS the zend object handle. Falls back to the node id
 *    prefixed with "node:" when the store branch is missing.
 *  - `size` of scalars is reported as sizeof(zval) = 16 to match
 *    php-meminfo accounting; other items report reli's measured
 *    allocation sizes (usually more accurate than php-meminfo's).
 *  - Non-standard extension fields are prefixed with "#reli_" so they
 *    cannot collide with upstream fields; known consumers ignore
 *    unknown fields.
 *
 * @psalm-suppress PossiblyNullPropertyFetch
 * @psalm-suppress UndefinedPropertyFetch
 * @psalm-suppress InvalidPropertyFetch
 * @psalm-suppress MixedAssignment
 * @psalm-suppress PossiblyInvalidArrayAccess
 */
final class PhpMeminfoFromRmemExporter
{
    /** php-meminfo counts sizeof(zval) for scalar items */
    private const SIZEOF_ZVAL = 16;

    /**
     * Base for synthetic item ids of nodes without a memory address
     * (scalars live inside their container's buckets). Bit 60 is never
     * set in canonical x86-64 user-space addresses, so these can not
     * collide with real locations.
     */
    private const SYNTHETIC_KEY_BASE = 0x1000000000000000;

    /** Length cap for the "#reli_value" extension field */
    private const VALUE_PREVIEW_MAX = 256;

    private const VALUE_NODE_TYPES = [
        'ObjectContext' => 'object',
        'ArrayHeaderContext' => 'array',
        'InlineArrayHeaderContext' => 'array',
        'StringContext' => 'string',
        'ScalarValueContext' => 'scalar',
        'ResourceContext' => 'resource',
    ];

    private StringDict $dict;

    private int $json_flags;

    /** @var array<int, int> node_id => type_id */
    private array $nodeTypeIds = [];

    /** @var array<int, string> type_id => type name */
    private array $typeNames = [];

    /** @var array<int, list<int>> parent_node_id => list of edge row indices */
    private array $childrenByParent = [];

    /** @var list<int> root edges (parent == NULL) */
    private array $rootEdges = [];

    /** @var array<int, list<int>> node_id => list of location row indices */
    private array $locationsByNode = [];

    /** @var array<int, list<int>> node_id => list of attribute row indices */
    private array $attrsByNode = [];

    private ?\FFI\CData $edgeRows = null;
    private string $edgeData = '';

    private ?\FFI\CData $locRows = null;
    private string $locData = '';

    /** @var array<int, string> item node_id => item key ("0x…") */
    private array $itemKeys = [];

    /** @var array<string, true> item keys already taken */
    private array $usedKeys = [];

    /** @var list<int> item node_ids in discovery order */
    private array $itemOrder = [];

    /** @var array<int, array<array-key, int>> item node_id => children (label => item node_id) */
    private array $itemChildren = [];

    /** @var array<int, array{string, string}> item node_id => [symbol_name, frame] */
    private array $itemRoots = [];

    /** @var array<int, string> object node_id => object handle (decimal string) */
    private array $objectHandles = [];

    public function __construct(
        private BinaryReader $reader,
        bool $pretty_print = false,
    ) {
        $this->dict = $reader->getStringDict();
        $this->json_flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($pretty_print) {
            $this->json_flags |= JSON_PRETTY_PRINT;
        }
        $this->buildIndices();
    }

    /**
     * @param array<int, array<string, mixed>> $summary
     * @param resource $output
     */
    public function export(array $summary, $output): void
    {
        $flat_summary = [];
        foreach ($summary as $row) {
            $flat_summary += $row;
        }

        $this->collectItems();

        fwrite($output, '{"header":' . $this->jsonEncode($this->buildHeader($flat_summary)));
        fwrite($output, ',"items":{');

        $php7 = $this->isPhp7Target($flat_summary);
        $first = true;
        foreach ($this->itemOrder as $node_id) {
            fwrite(
                $output,
                ($first ? '' : ',')
                . $this->jsonEncode($this->itemKeys[$node_id])
                . ':'
                . $this->jsonEncode($this->buildItem($node_id, $php7)),
            );
            $first = false;
        }
        fwrite($output, '}}' . "\n");
    }

    /**
     * @param array<string, mixed> $flat_summary
     * @return array<string, int>
     */
    private function buildHeader(array $flat_summary): array
    {
        $usage = $this->summaryInt($flat_summary, 'memory_get_usage');
        $real = $this->summaryInt($flat_summary, 'memory_get_real_usage');
        // Peak values are absent from rmems captured by older versions;
        // fall back to the current usage as the best lower bound.
        $peak = $this->summaryInt($flat_summary, 'memory_get_peak_usage') ?? $usage;
        $peak_real = $this->summaryInt($flat_summary, 'memory_get_peak_usage_real') ?? $real;
        return [
            'memory_usage' => $usage ?? 0,
            'memory_usage_real' => $real ?? 0,
            'peak_memory_usage' => $peak ?? 0,
            'peak_memory_usage_real' => $peak_real ?? 0,
        ];
    }

    /** @param array<string, mixed> $flat_summary */
    private function summaryInt(array $flat_summary, string $key): ?int
    {
        $value = $flat_summary[$key] ?? null;
        return is_numeric($value) ? (int)$value : null;
    }

    /** @param array<string, mixed> $flat_summary */
    private function isPhp7Target(array $flat_summary): bool
    {
        $php_version = $flat_summary['php_version'] ?? '';
        return is_string($php_version) && str_starts_with($php_version, 'v7');
    }

    /** @return array<string, mixed> */
    private function buildItem(int $node_id, bool $php7): array
    {
        $kind = self::VALUE_NODE_TYPES[$this->typeNameOf($node_id)] ?? 'scalar';
        $attrs = $kind === 'scalar' ? $this->getAttributes($node_id) : [];

        $item = [
            'type' => $kind === 'scalar'
                ? $this->scalarTypeName($attrs, $php7)
                : $kind,
            'size' => (string)$this->sizeOf($node_id, $kind),
        ];
        if (isset($this->itemRoots[$node_id])) {
            [$symbol_name, $frame] = $this->itemRoots[$node_id];
            $item['symbol_name'] = $symbol_name;
            $item['is_root'] = true;
            $item['frame'] = $frame;
        } else {
            $item['is_root'] = false;
        }
        if ($kind === 'object') {
            $item['class'] = $this->classNameOf($node_id) ?? '';
            $item['object_handle'] = $this->objectHandles[$node_id] ?? ('node:' . $node_id);
        }
        if ($kind === 'object' || $kind === 'array') {
            $children = [];
            foreach ($this->itemChildren[$node_id] ?? [] as $label => $child_id) {
                $children[$label] = $this->itemKeys[$child_id];
            }
            // an empty PHP array would encode as [] — force object form
            $item['children'] = (object)$children;
        }

        $item['#reli_node_id'] = $node_id;
        $value_preview = $this->valuePreview($node_id, $kind, $attrs);
        if ($value_preview !== null) {
            $item['#reli_value'] = $value_preview;
        }

        return $item;
    }

    /**
     * @param array<string, string> $attrs
     */
    private function scalarTypeName(array $attrs, bool $php7): string
    {
        $type = $attrs['value_type'] ?? $this->inferScalarType($attrs['value'] ?? '');
        return match ($type) {
            'bool' => $php7 ? 'boolean' : 'bool',
            'int' => $php7 ? 'integer' : 'int',
            'float' => 'float',
            default => 'null',
        };
    }

    /**
     * Best-effort fallback for rmems written before the value_type
     * attribute existed. The sink stores scalar attributes with a
     * plain string cast, so true and 1 both arrive as "1" — integers
     * win the tie here.
     */
    private function inferScalarType(string $value): string
    {
        if ($value === '') {
            return 'bool';
        }
        if ($value === 'null') {
            return 'null';
        }
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return 'int';
        }
        if (is_numeric($value) || $value === '["infinity"]' || $value === '["nan"]') {
            return 'float';
        }
        return 'null';
    }

    /**
     * @param array<string, string> $attrs
     */
    private function valuePreview(int $node_id, string $kind, array $attrs): ?string
    {
        $value = null;
        if ($kind === 'scalar') {
            $value = $attrs['value'] ?? null;
        } elseif ($kind === 'string') {
            foreach ($this->locationsByNode[$node_id] ?? [] as $loc_idx) {
                $loc = $this->readLocation($loc_idx);
                if ($loc['string_value'] !== null) {
                    $value = $loc['string_value'];
                    break;
                }
            }
        }
        if ($value === null) {
            return null;
        }
        if (strlen($value) > self::VALUE_PREVIEW_MAX) {
            $value = substr($value, 0, self::VALUE_PREVIEW_MAX) . '…';
        }
        return $value;
    }

    private function sizeOf(int $node_id, string $kind): int
    {
        if ($kind === 'scalar') {
            return self::SIZEOF_ZVAL;
        }
        $size = 0;
        foreach ($this->locationsByNode[$node_id] ?? [] as $loc_idx) {
            $size += $this->readLocation($loc_idx)['size'];
        }
        return $size;
    }

    private function classNameOf(int $node_id): ?string
    {
        foreach ($this->locationsByNode[$node_id] ?? [] as $loc_idx) {
            $class_name = $this->readLocation($loc_idx)['class_name'];
            if ($class_name !== null) {
                return $class_name;
            }
        }
        return null;
    }

    private function collectItems(): void
    {
        $categories = $this->rootCategories();

        if (isset($categories['objects_store'])) {
            $this->collectObjectHandles($categories['objects_store']);
        }
        foreach (['call_frames', 'memory_limit_call_frames'] as $category) {
            if (isset($categories[$category])) {
                $this->collectCallFrameRoots($categories[$category]);
            }
        }
        if (isset($categories['global_variables'])) {
            $this->collectArrayRoots($categories['global_variables'], '<GLOBAL>');
        }
        if (isset($categories['class_table'])) {
            $this->collectStaticPropertyRoots($categories['class_table']);
        }

        // Roots were queued first (addRoot() registers items), now add
        // every object from the store so that even root-unreachable
        // objects (e.g. collectible cycles) become items.
        foreach ($this->nodeTypeIds as $node_id => $_) {
            if ($this->typeNameOf($node_id) === 'ObjectContext') {
                $this->addItem($node_id);
            }
        }

        // Resolve children breadth-first; discovering children may add
        // more items to the order list.
        for ($i = 0; $i < count($this->itemOrder); $i++) {
            $this->collectChildren($this->itemOrder[$i]);
        }
    }

    /** @return array<string, int> category link name => node_id */
    private function rootCategories(): array
    {
        $categories = [];
        foreach ($this->rootEdges as $edge_idx) {
            [, $child, $link_name] = $this->readEdge($edge_idx);
            $categories[$link_name] = $child;
        }
        return $categories;
    }

    private function collectObjectHandles(int $objects_store_node): void
    {
        foreach ($this->childrenByParent[$objects_store_node] ?? [] as $edge_idx) {
            [, $child, $link_name] = $this->readEdge($edge_idx);
            if ($this->typeNameOf($child) === 'ObjectContext') {
                $this->objectHandles[$child] ??= $link_name;
            }
        }
    }

    private function collectCallFrameRoots(int $call_frames_node): void
    {
        foreach ($this->childrenByParent[$call_frames_node] ?? [] as $edge_idx) {
            [, $frame_node] = $this->readEdge($edge_idx);
            if ($this->typeNameOf($frame_node) !== 'CallFrameContext') {
                continue;
            }
            $frame_label = $this->frameLabel($frame_node);
            foreach ($this->childrenByParent[$frame_node] ?? [] as $frame_edge_idx) {
                [, $child, $link_name] = $this->readEdge($frame_edge_idx);
                if ($link_name === 'local_variables') {
                    foreach ($this->childrenByParent[$child] ?? [] as $var_edge_idx) {
                        [, $var_node, $var_name] = $this->readEdge($var_edge_idx);
                        $this->addRoot($var_node, $var_name, $frame_label);
                    }
                } elseif ($link_name === 'symbol_table') {
                    foreach ($this->arrayElements($child) as [$key, $value_node]) {
                        $this->addRoot($value_node, (string)$key, $frame_label);
                    }
                }
            }
        }
    }

    private function frameLabel(int $frame_node): string
    {
        $function_name = $this->getAttributes($frame_node)['function_name'] ?? '';
        if ($function_name === '<main>' || $function_name === '') {
            return '<GLOBAL>';
        }
        return $function_name . '()';
    }

    private function collectArrayRoots(int $array_node, string $frame_label): void
    {
        foreach ($this->arrayElements($array_node) as [$key, $value_node]) {
            $this->addRoot($value_node, (string)$key, $frame_label);
        }
    }

    private function collectStaticPropertyRoots(int $class_table_node): void
    {
        foreach ($this->childrenByParent[$class_table_node] ?? [] as $edge_idx) {
            [, $class_def_node, $class_table_key] = $this->readEdge($edge_idx);
            if ($this->typeNameOf($class_def_node) !== 'ClassDefinitionContext') {
                continue;
            }
            $class_name = null;
            $static_properties_node = null;
            foreach ($this->childrenByParent[$class_def_node] ?? [] as $class_edge_idx) {
                [, $child, $link_name] = $this->readEdge($class_edge_idx);
                if ($link_name === 'class_entry') {
                    $class_name = $this->getAttributes($child)['class_name'] ?? null;
                } elseif ($link_name === 'static_properties') {
                    $static_properties_node = $child;
                }
            }
            if ($static_properties_node === null) {
                continue;
            }
            // the class-table key is lowercased; prefer the original
            // spelling recorded on the class entry
            $class_name ??= $class_table_key;
            foreach ($this->childrenByParent[$static_properties_node] ?? [] as $prop_edge_idx) {
                [, $value_node, $prop_name] = $this->readEdge($prop_edge_idx);
                $this->addRoot($value_node, $class_name . '::' . $prop_name, '<CLASS_STATIC_MEMBER>');
            }
        }
    }

    private function addRoot(int $node_id, string $symbol_name, string $frame_label): void
    {
        $resolved = $this->resolveValueNode($node_id);
        if ($resolved === null) {
            return;
        }
        if (!isset($this->itemRoots[$resolved])) {
            // first occurrence wins, like the visited_items dedup in
            // the C extension
            $this->itemRoots[$resolved] = [$symbol_name, $frame_label];
        }
        $this->addItem($resolved);
    }

    /**
     * Register a node as an item and assign its key. Idempotent.
     */
    private function addItem(int $node_id): void
    {
        if (isset($this->itemKeys[$node_id])) {
            return;
        }
        $address = $this->primaryAddressOf($node_id);
        $key = $address !== null ? sprintf('0x%x', $address) : null;
        if ($key === null || isset($this->usedKeys[$key])) {
            $key = sprintf('0x%x', self::SYNTHETIC_KEY_BASE + $node_id);
        }
        $this->itemKeys[$node_id] = $key;
        $this->usedKeys[$key] = true;
        $this->itemOrder[] = $node_id;
    }

    private function primaryAddressOf(int $node_id): ?int
    {
        $indices = $this->locationsByNode[$node_id] ?? [];
        if ($indices === []) {
            return null;
        }
        return $this->readLocation($indices[0])['address'];
    }

    private function collectChildren(int $node_id): void
    {
        $kind = self::VALUE_NODE_TYPES[$this->typeNameOf($node_id)] ?? null;
        if ($kind === 'object') {
            $this->collectObjectChildren($node_id);
        } elseif ($kind === 'array') {
            $children = [];
            foreach ($this->arrayElements($node_id) as [$key, $value_node]) {
                $this->addItem($value_node);
                $children[$key] = $value_node;
            }
            $this->itemChildren[$node_id] = $children;
        }
    }

    private function collectObjectChildren(int $node_id): void
    {
        $children = [];
        foreach ($this->childrenByParent[$node_id] ?? [] as $edge_idx) {
            [, $child, $link_name] = $this->readEdge($edge_idx);
            if ($link_name === 'object_properties') {
                foreach ($this->childrenByParent[$child] ?? [] as $prop_edge_idx) {
                    [, $value_node, $prop_name] = $this->readEdge($prop_edge_idx);
                    $resolved = $this->resolveValueNode($value_node);
                    if ($resolved !== null) {
                        $this->addItem($resolved);
                        $children[$prop_name] = $resolved;
                    }
                }
            } elseif ($link_name === 'dynamic_properties') {
                // php-meminfo's debug-handler view mixes dynamic
                // properties in with the declared ones
                foreach ($this->arrayElements($child) as [$key, $value_node]) {
                    $this->addItem($value_node);
                    $children[$key] ??= $value_node;
                }
            } elseif ($link_name === 'closure') {
                // Closure use'd variables sit behind
                // closure -> func -> op_array -> static_variables;
                // php-meminfo exposes them as a "static" child
                $static_variables = $this->findClosureStaticVariables($child);
                if ($static_variables !== null) {
                    $this->addItem($static_variables);
                    $children['static'] = $static_variables;
                }
            }
        }
        $this->itemChildren[$node_id] = $children;
    }

    private function findClosureStaticVariables(int $closure_node): ?int
    {
        $func = $this->firstChildByLabel($closure_node, 'func');
        if ($func === null) {
            return null;
        }
        $op_array = $this->firstChildByLabel($func, 'op_array');
        if ($op_array === null) {
            return null;
        }
        $static_variables = $this->firstChildByLabel($op_array, 'static_variables');
        if ($static_variables === null) {
            return null;
        }
        return $this->resolveValueNode($static_variables);
    }

    /**
     * Iterate the logical elements of an array node:
     * ArrayHeaderContext -> 'array_elements' -> per-key
     * ArrayElementContext -> 'value'.
     *
     * @return iterable<array{array-key, int}> [key, value node_id]
     */
    private function arrayElements(int $array_node): iterable
    {
        $resolved = $this->resolveValueNode($array_node);
        if ($resolved === null) {
            return;
        }
        $elements_node = $this->firstChildByLabel($resolved, 'array_elements');
        if ($elements_node === null) {
            return;
        }
        foreach ($this->childrenByParent[$elements_node] ?? [] as $edge_idx) {
            [, $element_node, $key] = $this->readEdge($edge_idx);
            if ($this->typeNameOf($element_node) === 'ArrayElementContext') {
                $value_node = $this->firstChildByLabel($element_node, 'value');
                if ($value_node === null) {
                    continue;
                }
                $value_node = $this->resolveValueNode($value_node);
            } else {
                $value_node = $this->resolveValueNode($element_node);
            }
            if ($value_node !== null) {
                yield [$key, $value_node];
            }
        }
    }

    /**
     * Collapse reference wrappers until an item-representable node is
     * reached, mirroring php-meminfo's ZVAL_DEREF. Returns null for
     * nodes that have no php-meminfo representation (op arrays, class
     * entries, …).
     */
    private function resolveValueNode(int $node_id): ?int
    {
        $guard = 0;
        while ($guard++ < 32) {
            $type = $this->typeNameOf($node_id);
            if ($type === 'PhpReferenceContext') {
                $next = $this->firstChildByLabel($node_id, 'referenced');
                if ($next === null) {
                    return null;
                }
                $node_id = $next;
                continue;
            }
            if ($type === 'ArrayElementContext') {
                $next = $this->firstChildByLabel($node_id, 'value');
                if ($next === null) {
                    return null;
                }
                $node_id = $next;
                continue;
            }
            return isset(self::VALUE_NODE_TYPES[$type]) ? $node_id : null;
        }
        return null;
    }

    private function firstChildByLabel(int $node_id, string $label): ?int
    {
        foreach ($this->childrenByParent[$node_id] ?? [] as $edge_idx) {
            [, $child, $link_name] = $this->readEdge($edge_idx);
            if ($link_name === $label) {
                return $child;
            }
        }
        return null;
    }

    private function typeNameOf(int $node_id): string
    {
        $type_id = $this->nodeTypeIds[$node_id] ?? null;
        if ($type_id === null) {
            return '';
        }
        return $this->typeNames[$type_id] ??= ($this->dict->lookup($type_id) ?? '');
    }

    /** @return array<string, string> */
    private function getAttributes(int $node_id): array
    {
        $attrs = [];
        if (($this->attrsByNode[$node_id] ?? []) === []) {
            return $attrs;
        }
        $data = $this->reader->getSectionData(Format::SECTION_ATTRIBUTES);
        foreach ($this->attrsByNode[$node_id] as $attr_idx) {
            $row = unpack('Vnode_id/Vkey_id/Vvalue_id', $data, $attr_idx * 12);
            $key = $this->dict->lookup((int)$row['key_id']);
            $value = $this->dict->lookup((int)$row['value_id']);
            if ($key !== null && $value !== null) {
                $attrs[$key] = $value;
            }
        }
        return $attrs;
    }

    private function buildIndices(): void
    {
        $this->indexNodes();
        $this->indexEdges();
        $this->indexLocations();
        $this->indexAttributes();
    }

    private function indexNodes(): void
    {
        if (!$this->reader->hasSection(Format::SECTION_NODES)) {
            return;
        }
        $count = $this->reader->getSectionElementCount(Format::SECTION_NODES);
        $rows = $this->reader->castSection(Format::SECTION_NODES, 'NodeRow');
        if ($rows !== null) {
            for ($i = 0; $i < $count; $i++) {
                $this->nodeTypeIds[$rows[$i]->node_id] = $rows[$i]->type_id;
            }
            return;
        }
        $data = $this->reader->getSectionData(Format::SECTION_NODES);
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::NODE_ROW_SIZE;
            $row = unpack('Vnode_id/Vcanonical_id/Vtype_id', $data, $off);
            $this->nodeTypeIds[(int)$row['node_id']] = (int)$row['type_id'];
        }
    }

    private function indexEdges(): void
    {
        if (!$this->reader->hasSection(Format::SECTION_EDGES)) {
            return;
        }
        $count = $this->reader->getSectionElementCount(Format::SECTION_EDGES);
        $this->edgeRows = $this->reader->castSection(Format::SECTION_EDGES, 'EdgeRow');
        if ($this->edgeRows === null) {
            $this->edgeData = $this->reader->getSectionData(Format::SECTION_EDGES);
        }
        for ($i = 0; $i < $count; $i++) {
            [$parent] = $this->readEdge($i);
            if ($parent === Format::NULL_STRING_ID) {
                $this->rootEdges[] = $i;
            } else {
                $this->childrenByParent[$parent][] = $i;
            }
        }
    }

    private function indexLocations(): void
    {
        if (!$this->reader->hasSection(Format::SECTION_LOCATIONS)) {
            return;
        }
        $count = $this->reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $this->locRows = $this->reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');
        if ($this->locRows === null) {
            $this->locData = $this->reader->getSectionData(Format::SECTION_LOCATIONS);
        }
        for ($i = 0; $i < $count; $i++) {
            if ($this->locRows !== null) {
                $node_id = $this->locRows[$i]->node_id;
            } else {
                $node_id = unpack('V', $this->locData, $i * Format::LOCATION_ROW_SIZE)[1];
            }
            $this->locationsByNode[$node_id][] = $i;
        }
    }

    private function indexAttributes(): void
    {
        if (!$this->reader->hasSection(Format::SECTION_ATTRIBUTES)) {
            return;
        }
        $count = $this->reader->getSectionElementCount(Format::SECTION_ATTRIBUTES);
        $data = $this->reader->getSectionData(Format::SECTION_ATTRIBUTES);
        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            $node_id = unpack('V', $data, $offset)[1];
            $this->attrsByNode[$node_id][] = $i;
            $offset += 12;
        }
    }

    /**
     * @return array{int, int, string} parent_node_id, child_node_id, link_name
     */
    private function readEdge(int $edge_idx): array
    {
        if ($this->edgeRows !== null) {
            $parent = (int)$this->edgeRows[$edge_idx]->parent_node_id;
            $child = (int)$this->edgeRows[$edge_idx]->child_node_id;
            $link_id = (int)$this->edgeRows[$edge_idx]->link_name_id;
        } else {
            $off = $edge_idx * Format::EDGE_ROW_SIZE;
            $row = unpack('Vparent/Vchild/Vlid', $this->edgeData, $off);
            $parent = (int)$row['parent'];
            $child = (int)$row['child'];
            $link_id = (int)$row['lid'];
        }
        return [$parent, $child, $this->dict->lookup($link_id) ?? ''];
    }

    /**
     * @return array{address: int, size: int, class_name: ?string, string_value: ?string}
     */
    private function readLocation(int $loc_idx): array
    {
        if ($this->locRows !== null) {
            $address = (int)$this->locRows[$loc_idx]->address;
            $size = (int)$this->locRows[$loc_idx]->size;
            $class_id = (int)$this->locRows[$loc_idx]->class_id;
            $string_value_id = (int)$this->locRows[$loc_idx]->string_value_id;
        } else {
            $off = $loc_idx * Format::LOCATION_ROW_SIZE;
            $row = unpack(
                'Vnode_id/Vlocation_type_id/Vclass_id/Paddress/Psize/Vstring_value_id',
                $this->locData,
                $off,
            );
            $address = (int)$row['address'];
            $size = (int)$row['size'];
            $class_id = (int)$row['class_id'];
            $string_value_id = (int)$row['string_value_id'];
        }
        return [
            'address' => $address,
            'size' => $size,
            'class_name' => $class_id === Format::NULL_STRING_ID
                ? null
                : $this->dict->lookup($class_id),
            'string_value' => $string_value_id === Format::NULL_STRING_ID
                ? null
                : $this->dict->lookup($string_value_id),
        ];
    }

    private function jsonEncode(mixed $value): string
    {
        $result = json_encode($value, $this->json_flags);
        if ($result === false) {
            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $result;
    }
}
