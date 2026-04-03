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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ObjectsStoreMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectHandlersMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassDefinitionContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectHandlersContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectsStoreContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ScalarValueContext;

class EdgeStrengthTest extends BaseTestCase
{
    public function testObjectContextHandlersIsStructural(): void
    {
        $object = new ObjectContext(
            new ZendObjectMemoryLocation(0x1000, 64, 1, 7, 'App\\Foo'),
        );
        $this->assertSame(EdgeStrength::Structural, $object->getLinkStrength('object_handlers'));
    }

    public function testObjectContextPropertiesIsStrong(): void
    {
        $object = new ObjectContext(
            new ZendObjectMemoryLocation(0x1000, 64, 1, 7, 'App\\Foo'),
        );
        $this->assertSame(EdgeStrength::Strong, $object->getLinkStrength('object_properties'));
        $this->assertSame(EdgeStrength::Strong, $object->getLinkStrength('closure'));
        $this->assertSame(EdgeStrength::Strong, $object->getLinkStrength('dynamic_properties'));
    }

    public function testObjectsStoreAllChildrenAreWeak(): void
    {
        $store = new ObjectsStoreContext(
            new ObjectsStoreMemoryLocation(0x1000, 1024),
        );
        $this->assertSame(EdgeStrength::Weak, $store->getLinkStrength('0'));
        $this->assertSame(EdgeStrength::Weak, $store->getLinkStrength('any_link'));
    }

    public function testClassDefinitionClassEntryIsStructural(): void
    {
        $cls = new ClassDefinitionContext(is_internal: false);
        $this->assertSame(EdgeStrength::Structural, $cls->getLinkStrength('class_entry'));
    }

    public function testClassDefinitionMethodsIsStrong(): void
    {
        $cls = new ClassDefinitionContext(is_internal: false);
        $this->assertSame(EdgeStrength::Strong, $cls->getLinkStrength('methods'));
    }

    public function testDefaultLinkStrengthIsStrong(): void
    {
        // ScalarValueContext uses the default trait
        $ctx = new ScalarValueContext(42);
        $this->assertSame(EdgeStrength::Strong, $ctx->getLinkStrength('anything'));
    }

    public function testAnalyzerPassesEdgeStrengthToSink(): void
    {
        $handlers = \Mockery::mock(ReferenceContext::class);
        $handlers->allows('getName')->andReturns('handlers');
        $handlers->allows('getLinks')->andReturns([]);
        $handlers->allows('getLocations')->andReturns([]);
        $handlers->allows('getContexts')->andReturns([]);
        $handlers->allows('releaseLinks');
        $handlers->allows('getLinkStrength')->andReturns(EdgeStrength::Strong);

        $object = \Mockery::mock(ReferenceContext::class);
        $object->allows('getName')->andReturns('object');
        $object->allows('getLinks')->andReturns([
            'object_handlers' => $handlers,
        ]);
        $object->allows('getLocations')->andReturns([]);
        $object->allows('getContexts')->andReturns([]);
        $object->allows('releaseLinks');
        $object->allows('getLinkStrength')->with('object_handlers')->andReturns(EdgeStrength::Structural);

        $sink = \Mockery::mock(ContextTreeSink::class);
        $sink->allows('allowsRelease')->andReturns(false);
        $sink->expects('emitNode')->once()->withArgs(function (
            int $node_id,
            ?int $parent_node_id,
            string $link_name,
            string $type,
            iterable $locations,
            array $attributes,
            EdgeStrength $edge_strength,
        ): bool {
            return $link_name === 'object_handlers'
                && $edge_strength === EdgeStrength::Structural;
        });

        $analyzer = new ContextAnalyzer();
        $analyzer->analyze($object, $sink);
    }
}
