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
        $runs_data = pack('V', 1) // run_count = 1 (binary format is always single-run)
            . $this->packString(gmdate('Y-m-d\TH:i:s\Z'));
        $writer->writeSection(Format::SECTION_RUNS, $runs_data, 1);

        $writer->finish();

        // Clean up temp files
        $sink->cleanup();
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
