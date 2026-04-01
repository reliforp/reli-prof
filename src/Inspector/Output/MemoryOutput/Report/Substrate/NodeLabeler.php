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
        private \PDO $db,
        private int $run_id,
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

        // Load function_name and lineno from CallFrameContext nodes
        $rows = $this->db->query("
            SELECT
                a_fn.node_id,
                a_fn.value as function_name,
                a_ln.value as lineno
            FROM context_node_attributes a_fn
            LEFT JOIN context_node_attributes a_ln
                ON a_ln.node_id = a_fn.node_id
                AND a_ln.run_id = a_fn.run_id
                AND a_ln.key = 'lineno'
            WHERE a_fn.run_id = {$this->run_id}
                AND a_fn.key = 'function_name'
        ")->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $fn = $row['function_name'] ?? '';
            $ln = $row['lineno'] ?? '';
            $label = $ln !== '' ? "{$fn}:{$ln}" : $fn;
            $this->frame_labels[(int)$row['node_id']] = $label;
        }
    }
}
