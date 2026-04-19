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
 * Resolves node IDs to human-readable labels for report output.
 *
 * Call frame numbers become "function_name:lineno",
 * objects become their short class name, etc.
 */
final class NodeLabeler
{
    /** @var array<int, string> node_id => "function_name:lineno" */
    private array $frame_labels = [];

    /** @var bool */
    private bool $loaded = false;

    public function __construct(
        private ?\PDO $db,
        private int $run_id,
        /** @var array<int, string>|null Pre-loaded frame labels (binary path) */
        private ?array $preloaded_frame_labels = null,
    ) {
    }

    /**
     * Resolve a link_name like "1" into "functionName:42" if the
     * target node is a CallFrameContext with function_name attribute.
     *
     * @psalm-suppress MixedArrayAccess, MixedAssignment
     */
    public function resolvePathLabel(
        string $link_name,
        int $child_node_id,
    ): string {
        $this->ensureLoaded();

        if (isset($this->frame_labels[$child_node_id])) {
            return $this->frame_labels[$child_node_id];
        }

        return $link_name;
    }

    /** @psalm-suppress MixedArrayAccess, MixedAssignment, MixedPropertyTypeCoercion */
    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        // Binary path: use pre-loaded frame labels
        if ($this->preloaded_frame_labels !== null) {
            $this->frame_labels = $this->preloaded_frame_labels;
            return;
        }

        // SQL path
        if ($this->db === null) {
            return;
        }

        $rows = $this->db->query("
            SELECT node_id, \"key\", value
            FROM context_node_attributes
            WHERE run_id = {$this->run_id}
                AND \"key\" IN ('function_name', 'lineno')
        ")->fetchAll(\PDO::FETCH_NUM);

        /** @var array<int, array{function_name?:string, lineno?:string}> $by_node */
        $by_node = [];
        foreach ($rows as $row) {
            $node_id = (int)$row[0];
            $key = (string)$row[1];
            $value = $row[2] === null ? '' : (string)$row[2];
            if ($key === 'function_name') {
                $by_node[$node_id]['function_name'] = $value;
            } elseif ($key === 'lineno') {
                $by_node[$node_id]['lineno'] = $value;
            }
        }

        foreach ($by_node as $node_id => $kvs) {
            $fn = $kvs['function_name'] ?? '';
            if ($fn === '') {
                continue;
            }
            $ln = $kvs['lineno'] ?? '';
            $this->frame_labels[$node_id] = $ln !== '' ? "{$fn}:{$ln}" : $fn;
        }
    }
}
