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

namespace Reli\Rmem\Explore;

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\String\PathMap;

/**
 * Graph topology:
 *
 *   node 1  OpArrayContext   filename=/var/www/html/src/App.php:10-42
 *   ├── node 2  CallFrameContext  lineno=17, filename=...
 *   ├── node 3  ArrayContext      (no attributes — should inherit via held_by)
 *   ├── node 4  ClassEntryContext class_name=App\User, filename=.../User.php:5-20
 *   └── node 5  ObjectContext     class=App\User (defined_at → node 4)
 */
class RmemModelSourceLocationTest extends TestCase
{
    private string $rmem_path = '';

    #[\Override]
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reli_srcloc_test_');
        self::assertNotFalse($path);
        $this->rmem_path = $path . '.rmem';

        $sink = new BinaryContextTreeSink(batch_size: 10);

        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'op_array',
            type: 'OpArrayContext',
            locations: [new ZendArrayMemoryLocation(
                address: 0x1000,
                size: 100,
                refcount: 1,
                type_info: 7,
            )],
            attributes: [
                'filename' => '/var/www/html/src/App.php',
                'line_start' => 10,
                'line_end' => 42,
            ],
        );

        $sink->emitNode(
            node_id: 2,
            parent_node_id: 1,
            link_name: 'call_frame',
            type: 'CallFrameContext',
            locations: [new ZendArrayMemoryLocation(
                address: 0x2000,
                size: 80,
                refcount: 1,
                type_info: 7,
            )],
            attributes: [
                'function_name' => 'Foo::bar',
                'lineno' => 17,
                'filename' => '/var/www/html/src/App.php',
            ],
        );

        $sink->emitNode(
            node_id: 3,
            parent_node_id: 1,
            link_name: 'bare',
            type: 'ArrayContext',
            locations: [new ZendArrayMemoryLocation(
                address: 0x3000,
                size: 56,
                refcount: 1,
                type_info: 7,
            )],
            attributes: [],
        );

        $sink->emitNode(
            node_id: 4,
            parent_node_id: 1,
            link_name: 'class_entry',
            type: 'ClassEntryContext',
            locations: [new ZendArrayMemoryLocation(
                address: 0x4000,
                size: 400,
                refcount: 1,
                type_info: 7,
            )],
            attributes: [
                'class_name' => 'App\\User',
                'filename' => '/var/www/html/src/User.php',
                'line_start' => 5,
                'line_end' => 20,
            ],
        );

        $sink->emitNode(
            node_id: 5,
            parent_node_id: 1,
            link_name: 'user_obj',
            type: 'ObjectContext',
            locations: [new ZendObjectMemoryLocation(
                address: 0x5000,
                size: 200,
                refcount: 1,
                type_info: 7,
                class_name: 'App\\User',
            )],
            attributes: [],
        );

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '836', 'php_version' => '8.2.0'],
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (file_exists($this->rmem_path)) {
            @unlink($this->rmem_path);
        }
    }

    private function createModel(?PathMap $pathMap = null): RmemModel
    {
        $reader = BinaryReader::open($this->rmem_path);
        $substrate = GraphSubstrate::createFromBinary($reader, forceFfiCsr: false, skipScc: true);
        return RmemModel::fromSubstrate($substrate, $reader, $pathMap);
    }

    public function testSelfResolutionForOpArray(): void
    {
        $model = $this->createModel();
        $loc = $model->resolveSourceLocation(1);
        $this->assertNotNull($loc);
        $this->assertSame('/var/www/html/src/App.php', $loc['filename']);
        $this->assertSame(10, $loc['line_start']);
        $this->assertSame(42, $loc['line_end']);
        $this->assertSame(
            '/var/www/html/src/App.php:10-42',
            $model->formatSourceLocation(1),
        );
    }

    public function testSelfResolutionForCallFrameUsesLineno(): void
    {
        $model = $this->createModel();
        $this->assertSame(
            '/var/www/html/src/App.php:17',
            $model->formatSourceLocation(2),
        );
    }

    public function testHeldByClimbsAncestors(): void
    {
        $model = $this->createModel();
        // Node 3 has no filename of its own — should inherit via held_by
        // from its op_array parent (node 1).
        $this->assertNull($model->resolveSourceLocation(3));
        $locs = $model->resolveSourceLocations(3);
        $this->assertCount(1, $locs);
        $this->assertSame('held_by', $locs[0]['kind']);
        $this->assertSame('/var/www/html/src/App.php', $locs[0]['filename']);
    }

    public function testDefinedAtForObjectZval(): void
    {
        $model = $this->createModel();
        // Node 5 is an App\User object with no own filename. It should
        // resolve defined_at via the class_entry node (node 4).
        $locs = $model->resolveSourceLocations(5);
        $kinds = array_column($locs, 'kind');
        $this->assertContains('defined_at', $kinds);

        $definedAt = null;
        foreach ($locs as $loc) {
            if ($loc['kind'] === 'defined_at') {
                $definedAt = $loc;
                break;
            }
        }
        $this->assertNotNull($definedAt);
        $this->assertSame('/var/www/html/src/User.php', $definedAt['filename']);
        $this->assertSame(5, $definedAt['line_start']);
    }

    public function testNodesThatOwnLocationDoNotEmitHeldBy(): void
    {
        $model = $this->createModel();
        // Node 2 has a self filename — no held_by entry should be emitted.
        $locs = $model->resolveSourceLocations(2);
        $kinds = array_column($locs, 'kind');
        $this->assertSame(['self'], $kinds);
    }

    public function testNodeWithoutAnySourceReturnsEmpty(): void
    {
        // Create an isolated root node that has no parent with a filename.
        $path = tempnam(sys_get_temp_dir(), 'reli_srcloc_iso_');
        $isoPath = $path . '.rmem';
        $sink = new BinaryContextTreeSink(batch_size: 10);
        $sink->emitNode(
            node_id: 1,
            parent_node_id: null,
            link_name: 'bare_root',
            type: 'ArrayContext',
            locations: [new ZendArrayMemoryLocation(
                address: 0x1000,
                size: 10,
                refcount: 1,
                type_info: 7,
            )],
            attributes: [],
        );
        $binary_output = new BinaryMemoryOutput($isoPath);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '10', 'php_version' => '8.2.0'],
        ]);

        try {
            $reader = BinaryReader::open($isoPath);
            $substrate = GraphSubstrate::createFromBinary($reader, forceFfiCsr: false, skipScc: true);
            $model = RmemModel::fromSubstrate($substrate, $reader);
            $this->assertSame([], $model->resolveSourceLocations(1));
            $this->assertNull($model->formatSourceLocation(1));
        } finally {
            @unlink($isoPath);
        }
    }

    public function testNodeDetailExposesLocationsArray(): void
    {
        $model = $this->createModel();
        $detail = $model->nodeDetail(5);
        $this->assertSame('/var/www/html/src/User.php:5-20', $detail['source_location']);
        $this->assertNotEmpty($detail['source_locations']);
        $this->assertSame('defined_at', $detail['source_locations'][0]['kind']);
    }

    public function testBuildAndPrimeSourceLocationRefsRoundTrip(): void
    {
        $model = $this->createModel();

        $refs = $model->buildSourceLocationRefs();
        $this->assertIsString($refs['bytes']);
        $this->assertGreaterThan(0, $refs['count']);

        // Re-create a fresh model and prime it with the refs. defined_at
        // and held_by resolution should work without the live class-entry
        // scan (we blow away sourceLocationCache so the next call goes
        // through the primed refs).
        $fresh = $this->createModel();
        $fresh->primeSourceLocationRefs($refs['bytes'], $refs['count']);
        $locs = $fresh->resolveSourceLocations(5);
        $kinds = array_column($locs, 'kind');
        $this->assertContains('defined_at', $kinds);

        $held = $fresh->resolveSourceLocations(3);
        $kinds3 = array_column($held, 'kind');
        $this->assertContains('held_by', $kinds3);
    }

    public function testPathMapIsApplied(): void
    {
        $pathMap = new PathMap([
            '/var/www/html' => '/home/me/project',
        ]);
        $model = $this->createModel($pathMap);
        $this->assertSame(
            '/home/me/project/src/App.php:10-42',
            $model->formatSourceLocation(1),
        );
        $locs = $model->resolveSourceLocations(5);
        $definedAt = null;
        foreach ($locs as $loc) {
            if ($loc['kind'] === 'defined_at') {
                $definedAt = $loc;
                break;
            }
        }
        $this->assertNotNull($definedAt);
        $this->assertSame('/home/me/project/src/User.php', $definedAt['filename']);
    }
}
