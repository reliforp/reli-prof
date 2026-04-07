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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ObjectsStoreMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectsStoreContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\StringContext;

class ContextAnalyzerTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_ctx_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    public function testSentinelWithPreEmittedEdgeDoesNotDuplicateReference(): void
    {
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        [$sink, $run_id, $db] = $output->createStreamingSink();

        $analyzer = new ContextAnalyzer();
        $memo = new \WeakMap();

        $objects_store = new ObjectsStoreContext(
            new ObjectsStoreMemoryLocation(0x3000, 64),
        );
        $store_node_id = $analyzer->assignNodeId($objects_store, $memo);

        $child = new StringContext(
            new ZendStringMemoryLocation(0x1000, 16, 1, 0, 'hello'),
        );
        $analyzer->analyzeSingleLink(
            '1',
            $child,
            $sink,
            $store_node_id,
            $memo,
            EdgeStrength::Weak,
        );

        $child_node_id = $memo[$child] ?? null;
        $this->assertIsInt($child_node_id);

        $objects_store->add('1', -$child_node_id - 1);
        $analyzer->analyzeSingleLink(
            'objects_store',
            $objects_store,
            $sink,
            null,
            $memo,
            EdgeStrength::Weak,
        );
        $sink->flush();

        $edge_count_stmt = $db->prepare(
            'SELECT COUNT(*) FROM context_edges'
            . ' WHERE run_id = ? AND parent_node_id = ? AND child_node_id = ? AND link_name = ?'
        );
        $edge_count_stmt->execute([$run_id, $store_node_id, $child_node_id, '1']);
        $this->assertSame(1, (int)$edge_count_stmt->fetchColumn());

        $total_count_stmt = $db->prepare(
            'SELECT COUNT(*) FROM context_edges WHERE run_id = ?'
        );
        $total_count_stmt->execute([$run_id]);
        $this->assertSame(2, (int)$total_count_stmt->fetchColumn());

        $strength_stmt = $db->prepare(
            'SELECT strength FROM context_edges'
            . ' WHERE run_id = ? AND parent_node_id = ? AND child_node_id = ? AND link_name = ?'
        );
        $strength_stmt->execute([$run_id, $store_node_id, $child_node_id, '1']);
        $this->assertSame('weak', $strength_stmt->fetchColumn());

        $root_strength_stmt = $db->prepare(
            'SELECT strength FROM context_edges'
            . ' WHERE run_id = ? AND parent_node_id IS NULL AND child_node_id = ? AND link_name = ?'
        );
        $root_strength_stmt->execute([$run_id, $store_node_id, 'objects_store']);
        $this->assertSame('weak', $root_strength_stmt->fetchColumn());

        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    public function testPositiveNodeIdPlaceholderEmitsReferenceEdge(): void
    {
        $output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        [$sink, $run_id, $db] = $output->createStreamingSink();

        $analyzer = new ContextAnalyzer();
        $memo = new \WeakMap();

        $child = new StringContext(
            new ZendStringMemoryLocation(0x1000, 16, 1, 0, 'hello'),
        );
        $analyzer->analyzeSingleLink(
            'pre_emitted',
            $child,
            $sink,
            null,
            $memo,
            EdgeStrength::Weak,
        );

        $child_node_id = $memo[$child] ?? null;
        $this->assertIsInt($child_node_id);

        $objects_store = new ObjectsStoreContext(
            new ObjectsStoreMemoryLocation(0x3000, 64),
        );
        $objects_store->add('1', $child_node_id);
        $analyzer->analyzeSingleLink(
            'objects_store',
            $objects_store,
            $sink,
            null,
            $memo,
            EdgeStrength::Weak,
        );
        $sink->flush();

        $store_node_id_stmt = $db->prepare(
            'SELECT child_node_id FROM context_edges'
            . ' WHERE run_id = ? AND parent_node_id IS NULL AND link_name = ?'
        );
        $store_node_id_stmt->execute([$run_id, 'objects_store']);
        $store_node_id = $store_node_id_stmt->fetchColumn();
        $this->assertIsNumeric($store_node_id);

        $edge_count_stmt = $db->prepare(
            'SELECT COUNT(*) FROM context_edges'
            . ' WHERE run_id = ? AND parent_node_id = ? AND child_node_id = ? AND link_name = ?'
        );
        $edge_count_stmt->execute([$run_id, (int)$store_node_id, $child_node_id, '1']);
        $this->assertSame(1, (int)$edge_count_stmt->fetchColumn());

        $strength_stmt = $db->prepare(
            'SELECT strength FROM context_edges'
            . ' WHERE run_id = ? AND parent_node_id = ? AND child_node_id = ? AND link_name = ?'
        );
        $strength_stmt->execute([$run_id, (int)$store_node_id, $child_node_id, '1']);
        $this->assertSame('weak', $strength_stmt->fetchColumn());

        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}
