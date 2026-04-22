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
use Reli\Lib\String\PathMap;

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

        // node 1: op_array-like node with filename + line_start + line_end
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

        // node 2: call_frame-like node with filename + lineno
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

        // node 3: no source info
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

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '236', 'php_version' => '8.2.0'],
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

    public function testOpArrayNodeExposesFileAndLineRange(): void
    {
        $model = $this->createModel();
        $loc = $model->resolveSourceLocation(1);
        $this->assertNotNull($loc);
        $this->assertSame('/var/www/html/src/App.php', $loc['filename']);
        $this->assertSame(10, $loc['line_start']);
        $this->assertSame(42, $loc['line_end']);
        $this->assertSame(10, $loc['line']);
        $this->assertSame(
            '/var/www/html/src/App.php:10-42',
            $model->formatSourceLocation(1),
        );
    }

    public function testCallFrameNodeUsesLinenoOverLineStart(): void
    {
        $model = $this->createModel();
        $loc = $model->resolveSourceLocation(2);
        $this->assertNotNull($loc);
        $this->assertSame(17, $loc['line']);
        $this->assertSame(
            '/var/www/html/src/App.php:17',
            $model->formatSourceLocation(2),
        );
    }

    public function testNodeWithoutFilenameAttributeReturnsNull(): void
    {
        $model = $this->createModel();
        $this->assertNull($model->resolveSourceLocation(3));
        $this->assertNull($model->formatSourceLocation(3));
    }

    public function testNodeDetailExposesSourceLocation(): void
    {
        $model = $this->createModel();
        $detail = $model->nodeDetail(2);
        $this->assertSame('/var/www/html/src/App.php:17', $detail['source_location']);

        $detail3 = $model->nodeDetail(3);
        $this->assertNull($detail3['source_location']);
    }

    public function testPathMapIsApplied(): void
    {
        $pathMap = new PathMap([
            '/var/www/html' => '/home/me/project',
        ]);
        $model = $this->createModel($pathMap);
        $loc = $model->resolveSourceLocation(1);
        $this->assertNotNull($loc);
        $this->assertSame(
            '/home/me/project/src/App.php',
            $loc['filename'],
        );
        $this->assertSame(
            '/home/me/project/src/App.php:10-42',
            $model->formatSourceLocation(1),
        );
    }
}
