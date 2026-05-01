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
 * Streams JSON output from a .rmem binary file by DFS traversal.
 * Memory usage is O(nodes + edges) for the traversal indices, plus
 * O(tree depth) for the recursion.
 *
 * Output shape mirrors StreamingJsonFromDbExporter byte-for-byte for
 * the same logical input.
 *
 * @psalm-suppress InaccessibleMethod
 * @psalm-suppress PossiblyNullPropertyFetch
 * @psalm-suppress UndefinedPropertyFetch
 * @psalm-suppress InvalidPropertyFetch
 * @psalm-suppress MixedAssignment
 * @psalm-suppress PossiblyInvalidArrayAccess
 */
final class StreamingJsonFromRmemExporter
{
    private int $json_flags;

    private StringDict $dict;

    /** @var array<int, int> node_id => type_id */
    private array $nodeTypeIds = [];

    /** @var array<int, list<int>> parent_node_id => list of edge row indices */
    private array $childrenByParent = [];

    /** @var list<int> root edges (parent == NULL) */
    private array $rootEdges = [];

    /** @var array<int, list<int>> node_id => list of location row indices */
    private array $locationsByNode = [];

    /** @var array<int, list<int>> node_id => list of attribute row indices */
    private array $attrsByNode = [];

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
     * @param array<string, array{count: int, memory_usage: int}>|null $location_types_summary
     * @param array<string, array{count: int, memory_usage: int}>|null $class_objects_summary
     * @param resource $output
     */
    public function export(
        array $summary,
        ?array $location_types_summary,
        ?array $class_objects_summary,
        $output,
    ): void {
        fwrite($output, '{');
        fwrite($output, '"summary":' . $this->jsonEncode($summary));
        fwrite($output, ',"location_types_summary":' . $this->jsonEncode($location_types_summary ?? []));
        fwrite($output, ',"class_objects_summary":' . $this->jsonEncode($class_objects_summary ?? []));
        fwrite($output, ',"context":');
        $this->exportRootContext($output);
        fwrite($output, '}');
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
        $rows = $this->reader->castSection(Format::SECTION_EDGES, 'EdgeRow');
        if ($rows !== null) {
            for ($i = 0; $i < $count; $i++) {
                $parent = $rows[$i]->parent_node_id;
                if ($parent === Format::NULL_STRING_ID) {
                    $this->rootEdges[] = $i;
                } else {
                    $this->childrenByParent[$parent][] = $i;
                }
            }
            return;
        }
        $data = $this->reader->getSectionData(Format::SECTION_EDGES);
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::EDGE_ROW_SIZE;
            $parent = unpack('V', $data, $off)[1];
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
        $rows = $this->reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');
        if ($rows !== null) {
            for ($i = 0; $i < $count; $i++) {
                $this->locationsByNode[$rows[$i]->node_id][] = $i;
            }
            return;
        }
        $data = $this->reader->getSectionData(Format::SECTION_LOCATIONS);
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::LOCATION_ROW_SIZE;
            $node_id = unpack('V', $data, $off)[1];
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

    /** @param resource $output */
    private function exportRootContext($output): void
    {
        fwrite($output, '{');
        $first = true;
        $rows = $this->reader->castSection(Format::SECTION_EDGES, 'EdgeRow');
        $data = $rows === null
            ? $this->reader->getSectionData(Format::SECTION_EDGES)
            : '';
        foreach ($this->rootEdges as $edge_idx) {
            [$child_id, $link_name, $is_tree] = $this->readEdge($edge_idx, $rows, $data);
            if (!$first) {
                fwrite($output, ',');
            }
            $first = false;
            fwrite($output, $this->jsonEncode($link_name) . ':');
            if ($is_tree) {
                $this->exportNode($child_id, $output, $rows, $data);
            } else {
                fwrite($output, $this->jsonEncode(['#reference_node_id' => $child_id]));
            }
        }
        fwrite($output, '}');
    }

    /**
     * @param \FFI\CData|null $edgeRows
     * @param resource $output
     */
    private function exportNode(int $node_id, $output, $edgeRows, string $edgeData): void
    {
        $type_id = $this->nodeTypeIds[$node_id] ?? null;
        $type = $type_id !== null ? ($this->dict->lookup($type_id) ?? '') : '';

        fwrite($output, '{');
        fwrite($output, '"#node_id":' . $node_id);
        fwrite($output, ',"#type":' . $this->jsonEncode($type));

        $locations = $this->buildLocations($node_id);
        if ($locations !== []) {
            fwrite($output, ',"#locations":' . $this->jsonEncode($locations));
        }

        foreach ($this->attrsByNode[$node_id] ?? [] as $attr_idx) {
            [$key, $value] = $this->readAttribute($attr_idx);
            if ($key === null) {
                continue;
            }
            $decoded = json_decode((string)$value);
            $out_value = $decoded !== null ? $decoded : $value;
            fwrite($output, ',' . $this->jsonEncode($key) . ':' . $this->jsonEncode($out_value));
        }

        foreach ($this->childrenByParent[$node_id] ?? [] as $edge_idx) {
            [$child_id, $link_name, $is_tree] = $this->readEdge($edge_idx, $edgeRows, $edgeData);
            fwrite($output, ',' . $this->jsonEncode($link_name) . ':');
            if ($is_tree) {
                $this->exportNode($child_id, $output, $edgeRows, $edgeData);
            } else {
                fwrite($output, $this->jsonEncode(['#reference_node_id' => $child_id]));
            }
        }

        fwrite($output, '}');
    }

    /**
     * @param \FFI\CData|null $rows
     * @return array{int, string, int} child_node_id, link_name, is_tree
     */
    private function readEdge(int $edge_idx, $rows, string $data): array
    {
        if ($rows !== null) {
            $child = (int)$rows[$edge_idx]->child_node_id;
            $link_id = (int)$rows[$edge_idx]->link_name_id;
            $is_tree = (int)$rows[$edge_idx]->is_tree;
        } else {
            $off = $edge_idx * Format::EDGE_ROW_SIZE;
            $row = unpack('Vparent/Vchild/Vlid/Cis_tree', $data, $off);
            $child = (int)$row['child'];
            $link_id = (int)$row['lid'];
            $is_tree = (int)$row['is_tree'];
        }
        $link_name = $this->dict->lookup($link_id) ?? '';
        return [$child, $link_name, $is_tree];
    }

    /**
     * @return array{?string, ?string} key, value
     */
    private function readAttribute(int $attr_idx): array
    {
        $data = $this->reader->getSectionData(Format::SECTION_ATTRIBUTES);
        $off = $attr_idx * 12;
        $row = unpack('Vnode_id/Vkey_id/Vvalue_id', $data, $off);
        $key = $this->dict->lookup((int)$row['key_id']);
        $value = $this->dict->lookup((int)$row['value_id']);
        return [$key, $value];
    }

    /**
     * @return list<array{
     *     address: int,
     *     size: int,
     *     location_type: string,
     *     class_name: ?string,
     *     string_value: ?string,
     *     refcount: int,
     *     type_info: int,
     *     region: ?string
     * }>
     */
    private function buildLocations(int $node_id): array
    {
        $indices = $this->locationsByNode[$node_id] ?? [];
        if ($indices === []) {
            return [];
        }
        $rows = $this->reader->castSection(Format::SECTION_LOCATIONS, 'LocationRow');
        $data = $rows === null
            ? $this->reader->getSectionData(Format::SECTION_LOCATIONS)
            : '';
        $result = [];
        foreach ($indices as $idx) {
            if ($rows !== null) {
                $location_type_id = $rows[$idx]->location_type_id;
                $class_id = $rows[$idx]->class_id;
                $address = $rows[$idx]->address;
                $size = $rows[$idx]->size;
                $string_value_id = $rows[$idx]->string_value_id;
                $refcount = $rows[$idx]->refcount;
                $type_info = $rows[$idx]->type_info;
                $region_id = $rows[$idx]->region_id;
            } else {
                $off = $idx * Format::LOCATION_ROW_SIZE;
                $row = unpack(
                    'Vnode_id/Vlocation_type_id/Vclass_id/Paddress/Psize'
                    . '/Vstring_value_id/Vrefcount/Vtype_info/Vregion_id',
                    $data,
                    $off,
                );
                $location_type_id = (int)$row['location_type_id'];
                $class_id = (int)$row['class_id'];
                $address = (int)$row['address'];
                $size = (int)$row['size'];
                $string_value_id = (int)$row['string_value_id'];
                $refcount = (int)$row['refcount'];
                $type_info = (int)$row['type_info'];
                $region_id = (int)$row['region_id'];
            }
            $result[] = [
                'address' => $address,
                'size' => $size,
                'location_type' => $this->dict->lookup($location_type_id) ?? '',
                'class_name' => $class_id === Format::NULL_STRING_ID
                    ? null
                    : $this->dict->lookup($class_id),
                'string_value' => $string_value_id === Format::NULL_STRING_ID
                    ? null
                    : $this->dict->lookup($string_value_id),
                'refcount' => $refcount,
                'type_info' => $type_info,
                'region' => $region_id === Format::NULL_STRING_ID
                    ? null
                    : $this->dict->lookup($region_id),
            ];
        }
        return $result;
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
