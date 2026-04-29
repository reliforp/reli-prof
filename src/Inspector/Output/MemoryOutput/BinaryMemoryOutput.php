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

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\DiskBackedStringDict;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Writer;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

/**
 * Produces a .rmem binary intermediate file from a BinaryContextTreeSink.
 *
 * The streaming flow mirrors PdoMemoryOutput:
 *   1. createStreamingSink() → BinaryContextTreeSink (used during collection)
 *   2. finalizeStreaming()   → assemble the .rmem from the sink's temp files
 */
final class BinaryMemoryOutput implements MemoryOutputInterface
{
    private string $outputPath;

    public function __construct(
        string $output_path,
        private ?RegionBoundaries $region_boundaries = null,
        private int $batch_size = 200000,
    ) {
        $this->outputPath = $output_path;
    }

    #[\Override]
    public function output(MemoryAnalysisResult $result): void
    {
        // BinaryMemoryOutput only supports the streaming path.
        // The non-streaming path (from a pre-populated DB) would require
        // reading the DB and re-emitting through a BinaryContextTreeSink,
        // which is handled by the caller.
        throw new \RuntimeException(
            'BinaryMemoryOutput does not support the non-streaming output() path. '
            . 'Use createStreamingSink() + finalizeStreaming() instead.'
        );
    }

    /**
     * Create a streaming sink for binary output.
     *
     * @return BinaryContextTreeSink
     */
    public function createStreamingSink(): BinaryContextTreeSink
    {
        return new BinaryContextTreeSink($this->region_boundaries, $this->batch_size);
    }

    /**
     * Finalize the .rmem file from a streaming sink's temp data.
     *
     * Assembles the final binary file by:
     *   1. Writing the string dictionary section
     *   2. Copying node/edge/location/attribute temp files as sections
     *   3. Writing summary and run metadata sections
     *   4. Writing the TOC and header
     *
     * @param array<int, array<string, mixed>> $summary
     */
    public function finalizeStreaming(BinaryContextTreeSink $sink, array $summary): void
    {
        $sink->flush();

        // Pre-intern summary strings into the dict BEFORE serializing it,
        // so the dict includes all strings from all sections.
        $summary_data = $this->serializeSummary($summary, $sink->getStringDict());
        $summary_count = 0;
        /** @psalm-suppress MixedAssignment */
        foreach ($summary as $entry) {
            $summary_count += count($entry);
        }

        $writer = new Writer($this->outputPath);

        // Section 1: string_dict — streamed directly to the output file
        // to avoid materializing potentially hundreds of MB in a PHP string.
        $dict = $sink->getStringDict();
        $writer->writeSectionWithCallback(
            Format::SECTION_STRING_DICT,
            fn ($fh) => $dict->serializeToStream($fh),
            $dict->count(),
        );

        // Sections 2–5: stream from temp files (avoids loading
        // multi-GB section data into PHP memory).
        $writer->writeSectionFromFile(
            Format::SECTION_NODES,
            $sink->getNodeTmpPath(),
            $sink->getNodeCount(),
        );
        $writer->writeSectionFromFile(
            Format::SECTION_EDGES,
            $sink->getEdgeTmpPath(),
            $sink->getEdgeCount(),
        );
        $writer->writeSectionFromFile(
            Format::SECTION_LOCATIONS,
            $sink->getLocationTmpPath(),
            $sink->getLocationCount(),
        );
        $writer->writeSectionFromFile(
            Format::SECTION_ATTRIBUTES,
            $sink->getAttrTmpPath(),
            $sink->getAttrCount(),
        );

        // Section 6: summary (already serialized above)
        $writer->writeSection(Format::SECTION_SUMMARY, $summary_data, $summary_count);

        // Section 7: runs metadata
        $runs_data = pack('V', 1) // run_count = 1 (rmem format is always single-run)
            . $this->packString(gmdate('Y-m-d\TH:i:s\Z'));
        $writer->writeSection(Format::SECTION_RUNS, $runs_data, 1);

        // Sections 8-10: Per-node sizes/classes + canonical map. All three
        // share the same `$nodeSlots = $maxNodeId + 1` width and run only
        // when at least one node was emitted; keep them in a single block
        // so $nodeSlots's scope is unambiguous.
        $maxNodeId = $sink->getMaxNodeId();
        if ($maxNodeId >= 0) {
            $nodeSlots = $maxNodeId + 1;
            $perNodeSizes = $sink->getPerNodeSizes();
            if ($perNodeSizes !== null) {
                $writer->writeSection(
                    'node_sizes',
                    \FFI::string($perNodeSizes, $nodeSlots * 8),
                    $nodeSlots,
                );
            }
            $perNodeClasses = $sink->getPerNodeClasses();
            if ($perNodeClasses !== null) {
                $writer->writeSection(
                    'node_classes',
                    \FFI::string($perNodeClasses, $nodeSlots * 4),
                    $nodeSlots,
                );
            }
            $canonicalMap = $sink->buildCanonicalMap();
            if ($canonicalMap !== null) {
                $writer->writeSection(
                    'canonical_map',
                    \FFI::string($canonicalMap, $nodeSlots * 4),
                    $nodeSlots,
                );
            }
        }

        // Section 11+: On-disk CSR sections for fast report loading.
        // Built from the edge temp file in two passes without loading
        // all edges into PHP memory.
        $this->buildCsrSections(
            $writer,
            $sink->getEdgeTmpPath(),
            $sink->getEdgeCount(),
            $sink->getNodeCount(),
        );

        $writer->finish();

        // Clean up temp files
        $sink->cleanup();
    }

    /**
     * Build CSR sections from the edge temp file and write them
     * to the rmem. Uses two passes over the temp file to avoid
     * loading all edges into PHP memory:
     *
     * Pass 1: count degrees per parent (tree and all-edges)
     * Pass 2: fill col_idx arrays at the correct positions
     *
     * Produces:
     * - SECTION_TREE_CSR_ROWPTR / COLIDX / LINKNAMES / STRENGTH
     * - SECTION_TREE_PARENTS
     * - SECTION_NONTREE_CSR_ROWPTR / COLIDX
     */
    private function buildCsrSections(
        Writer $writer,
        string $edgeTmpPath,
        int $edgeCount,
        int $nodeCount,
    ): void {
        if ($edgeCount === 0 || $nodeCount === 0) {
            return;
        }

        // node_id range: 0..nodeCount-1 plus -1 sentinel (mapped to nodeCount)
        $nc = $nodeCount + 1; // +1 for sentinel -1 → index $nodeCount

        // Allocate degree arrays
        $treeDeg = FFIHelper::newInt32Array($nc);
        $allDeg = FFIHelper::newInt32Array($nc);
        $nontreeDeg = FFIHelper::newInt32Array($nc);

        // Tree parent tracking
        $treeParents = FFIHelper::newInt32Array($nc);
        for ($i = 0; $i < $nc; $i++) {
            $treeParents[$i] = -1;
        }

        $treeEdgeCount = 0;
        $nontreeEdgeCount = 0;

        // ---- Pass 1: count degrees ----
        $fp = fopen($edgeTmpPath, 'rb');
        if ($fp === false) {
            return;
        }

        $chunk_size = 16 * 10000; // read 10K edges at a time
        $remaining = $edgeCount;
        while ($remaining > 0) {
            $batch = min($remaining, 10000);
            $data = fread($fp, $batch * 16);
            if ($data === false || strlen($data) < $batch * 16) {
                break;
            }
            for ($i = 0; $i < $batch; $i++) {
                $off = $i * 16;
                /** @var array{1: int} */
                $parent_raw = unpack('V', $data, $off);
                $parent = $parent_raw[1];
                if ($parent === 0xFFFFFFFF) {
                    $parent_idx = $nodeCount; // sentinel -1
                } else {
                    $parent_idx = $parent;
                }
                $is_tree = ord($data[$off + 12]);

                if ($parent_idx < $nc) {
                    $allDeg[$parent_idx] = $allDeg[$parent_idx] + 1;
                    if ($is_tree === 1) {
                        $treeDeg[$parent_idx] = $treeDeg[$parent_idx] + 1;
                        $treeEdgeCount++;

                        // Track tree parent for child
                        /** @var array{1: int} */
                        $child_raw = unpack('V', $data, $off + 4);
                        $child_idx = $child_raw[1];
                        if ($child_idx < $nc) {
                            $treeParents[$child_idx] = $parent_idx;
                        }
                    } else {
                        $nontreeDeg[$parent_idx] = $nontreeDeg[$parent_idx] + 1;
                        $nontreeEdgeCount++;
                    }
                }
            }
            $remaining -= $batch;
        }
        fclose($fp);

        // ---- Build row_ptr from degrees ----
        $treeRowPtr = FFIHelper::newInt32Array($nc + 1);
        $allRowPtr = FFIHelper::newInt32Array($nc + 1);
        $nontreeRowPtr = FFIHelper::newInt32Array($nc + 1);

        $treeRowPtr[0] = 0;
        $allRowPtr[0] = 0;
        $nontreeRowPtr[0] = 0;
        for ($i = 0; $i < $nc; $i++) {
            $treeRowPtr[$i + 1] = $treeRowPtr[$i] + $treeDeg[$i];
            $allRowPtr[$i + 1] = $allRowPtr[$i] + $allDeg[$i];
            $nontreeRowPtr[$i + 1] = $nontreeRowPtr[$i] + $nontreeDeg[$i];
        }

        $totalAllEdges = $allRowPtr[$nc];
        $totalTreeEdges = $treeRowPtr[$nc];
        $totalNontreeEdges = $nontreeRowPtr[$nc];

        // Allocate col_idx + link_name + strength arrays
        $allColIdx = FFIHelper::newInt32Array(max(1, $totalAllEdges));
        $treeColIdx = FFIHelper::newInt32Array(max(1, $totalTreeEdges));
        $treeLinkNames = FFIHelper::newInt32Array(max(1, $totalTreeEdges));
        $treeStrength = FFIHelper::newInt8Array(max(1, $totalTreeEdges));
        $nontreeColIdx = FFIHelper::newInt32Array(max(1, $totalNontreeEdges));

        // Position counters (reuse degree arrays)
        $treePos = FFIHelper::newInt32Array($nc);
        $allPos = FFIHelper::newInt32Array($nc);
        $nontreePos = FFIHelper::newInt32Array($nc);
        for ($i = 0; $i < $nc; $i++) {
            $treePos[$i] = $treeRowPtr[$i];
            $allPos[$i] = $allRowPtr[$i];
            $nontreePos[$i] = $nontreeRowPtr[$i];
        }

        // ---- Pass 2: fill col_idx ----
        $fp = fopen($edgeTmpPath, 'rb');
        if ($fp === false) {
            return;
        }

        $remaining = $edgeCount;
        while ($remaining > 0) {
            $batch = min($remaining, 10000);
            $data = fread($fp, $batch * 16);
            if ($data === false || strlen($data) < $batch * 16) {
                break;
            }
            for ($i = 0; $i < $batch; $i++) {
                $off = $i * 16;
                /** @var array{1: int} */
                $parent_raw = unpack('V', $data, $off);
                $parent = $parent_raw[1];
                $parent_idx = ($parent === 0xFFFFFFFF) ? $nodeCount : $parent;

                /** @var array{1: int} */
                $child_raw = unpack('V', $data, $off + 4);
                $child_idx = $child_raw[1];

                /** @var array{1: int} */
                $link_raw = unpack('V', $data, $off + 8);
                $link_name_id = $link_raw[1];

                $is_tree = ord($data[$off + 12]);
                $strength = ord($data[$off + 13]);

                if ($parent_idx < $nc) {
                    $pos = $allPos[$parent_idx];
                    $allColIdx[$pos] = $child_idx;
                    $allPos[$parent_idx] = $pos + 1;

                    if ($is_tree === 1) {
                        $pos = $treePos[$parent_idx];
                        $treeColIdx[$pos] = $child_idx;
                        $treeLinkNames[$pos] = $link_name_id;
                        $treeStrength[$pos] = $strength;
                        $treePos[$parent_idx] = $pos + 1;
                    } else {
                        $pos = $nontreePos[$parent_idx];
                        $nontreeColIdx[$pos] = $child_idx;
                        $nontreePos[$parent_idx] = $pos + 1;
                    }
                }
            }
            $remaining -= $batch;
        }
        fclose($fp);

        // ---- Write CSR sections ----
        // Helper to serialize FFI array to string
        $ffiToString = function (\FFI\CData $arr, int $count, int $elem_size): string {
            return \FFI::string($arr, $count * $elem_size);
        };

        $writer->writeSection(
            'tree_csr_rowptr',
            $ffiToString($treeRowPtr, $nc + 1, 4),
            $nc + 1,
        );
        $writer->writeSection(
            'tree_csr_colidx',
            $ffiToString($treeColIdx, max(1, $totalTreeEdges), 4),
            $totalTreeEdges,
        );
        $writer->writeSection(
            'tcsr_links',
            $ffiToString($treeLinkNames, max(1, $totalTreeEdges), 4),
            $totalTreeEdges,
        );
        $writer->writeSection(
            'tcsr_strength',
            $ffiToString($treeStrength, max(1, $totalTreeEdges), 1),
            $totalTreeEdges,
        );
        $writer->writeSection(
            'tree_parents',
            $ffiToString($treeParents, $nc, 4),
            $nc,
        );
        $writer->writeSection(
            'ntcsr_rowptr',
            $ffiToString($nontreeRowPtr, $nc + 1, 4),
            $nc + 1,
        );
        $writer->writeSection(
            'ntcsr_colidx',
            $ffiToString($nontreeColIdx, max(1, $totalNontreeEdges), 4),
            $totalNontreeEdges,
        );
    }

    /**
     * Serialize summary as [entry_count:u32] then for each entry [key_id:u32, value_id:u32].
     * Keys and values are interned in the string dict.
     *
     * @param array<int, array<string, mixed>> $summary
     */
    private function serializeSummary(array $summary, DiskBackedStringDict $dict): string
    {
        $entries = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($summary as $entry) {
            foreach ($entry as $key => $value) {
                $string_value = is_scalar($value) ? (string)$value : json_encode($value);
                assert(is_string($string_value));
                $entries[] = [$dict->intern($key), $dict->intern($string_value)];
            }
        }

        $buf = pack('V', count($entries));
        foreach ($entries as [$key_id, $value_id]) {
            $buf .= pack('VV', $key_id, $value_id);
        }
        return $buf;
    }

    private function packString(string $s): string
    {
        return pack('V', strlen($s)) . $s;
    }
}
